/*
 * WebRTC call engine.
 *
 * Everything native (RTCPeerConnection, MediaStream, tracks, the remote
 * <audio> element) stays in this module. Alpine only receives plain values
 * through the "call" store. Socket listeners are registered exactly once.
 */

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const csrf = () => meta('csrf-token') || '';
const myId = () => Number(meta('user-id') || 0);
const isSecure = () => window.isSecureContext
    || location.hostname === 'localhost'
    || location.hostname === '127.0.0.1'
    || location.hostname === '[::1]';

const FALLBACK_ICE = [{ urls: 'stun:stun.l.google.com:19302' }];
const RING_TIMEOUT = 30000;
const RECONNECT_TIMEOUT = 20000;
const HTTPS_MESSAGE = 'Calls need HTTPS. Open the app over HTTPS (or localhost during development) to use your microphone.';

const store = () => window.Alpine?.store('call');
const patch = (values) => {
    const target = store();
    if (target) Object.assign(target, values);
};

const tabId = (() => {
    try {
        return crypto.randomUUID();
    } catch {
        return `tab-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }
})();

const state = {
    initialized: false,
    bound: false,
    channel: null,
    connectionHandler: null,
    tabId,

    call: null,
    role: null,
    peer: null,
    startedAt: null,

    pc: null,
    pcPromise: null,
    localStream: null,
    micPromise: null,
    remoteAudio: null,
    pendingIce: [],
    iceServers: FALLBACK_ICE,
    configLoaded: false,
    configPromise: null,
    ringtoneEnabled: true,
    ringtoneVolume: 100,

    toneTimer: null,
    audioCtx: null,
    durationTimer: null,
    statsTimer: null,
    ringTimer: null,
    answerTimer: null,
    reconnectTimer: null,
    resetTimer: null,
    ringingAcked: null,
};

/* ------------------------------------------------------------------ */
/* HTTP helpers                                                        */
/* ------------------------------------------------------------------ */

async function request(url, options = {}) {
    const { headers = {}, body, ...rest } = options;
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...rest,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        body,
    });

    let data = null;
    try { data = await response.json(); } catch { /* empty response */ }

    if (!response.ok) {
        const error = new Error(data?.message || `Request failed (${response.status})`);
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}

function beacon(url, data = {}) {
    if (!navigator.sendBeacon) return;
    try {
        const params = new URLSearchParams({ _token: csrf(), ...data });
        navigator.sendBeacon(url, params);
    } catch { /* best effort */ }
}

function friendlyError(error) {
    const name = error?.name || '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return 'Microphone permission was denied. Allow microphone access in your browser settings, then try again.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return 'No microphone was found. Connect a microphone and try again.';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return 'Your microphone is in use by another app. Close it and try again.';
    }
    if (name === 'SecurityError' || !isSecure()) {
        return HTTPS_MESSAGE;
    }
    return error?.message || 'Could not start the audio call.';
}

function preferredMicId() {
    try { return localStorage.getItem(`incognito-mic-device-${myId()}`) || ''; } catch { return ''; }
}

function preferredSpeakerId() {
    try { return localStorage.getItem(`incognito-speaker-device-${myId()}`) || ''; } catch { return ''; }
}

/* ------------------------------------------------------------------ */
/* Audio / media                                                       */
/* ------------------------------------------------------------------ */

function ensureRemoteAudio() {
    if (state.remoteAudio) return state.remoteAudio;

    const audio = document.createElement('audio');
    audio.autoplay = true;
    audio.playsInline = true;
    audio.setAttribute('playsinline', '');
    audio.className = 'call-audio';
    document.body.appendChild(audio);
    state.remoteAudio = audio;

    const savedSpeaker = preferredSpeakerId();
    if (savedSpeaker && typeof audio.setSinkId === 'function') {
        audio.setSinkId(savedSpeaker).catch(() => {});
    }

    return audio;
}

async function getMicrophone() {
    if (state.localStream && state.localStream.active) return state.localStream;
    if (state.micPromise) return state.micPromise;

    state.micPromise = (async () => {
        // getUserMedia is only exposed in a secure context. Check both so an
        // insecure LAN origin gets a clear message instead of a generic error.
        if (!window.isSecureContext || !isSecure()) {
            const error = new Error(HTTPS_MESSAGE);
            error.name = 'SecurityError';
            throw error;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            const error = new Error(HTTPS_MESSAGE);
            error.name = 'SecurityError';
            throw error;
        }

        const audio = {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        };

        const deviceId = preferredMicId();
        if (deviceId) audio.deviceId = { exact: deviceId };

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
        } catch (error) {
            if (deviceId && (error.name === 'OverconstrainedError' || error.name === 'NotFoundError')) {
                delete audio.deviceId;
                stream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
            } else {
                throw error;
            }
        }

        // The user may have ended the call while the permission prompt was
        // open. Never leave a hot microphone behind in that case.
        const status = store()?.status;
        if (!['calling', 'incoming', 'connecting', 'active', 'reconnecting'].includes(status)) {
            stream.getTracks().forEach((track) => {
                try { track.stop(); } catch { /* ignore */ }
            });
            throw new Error('Call cancelled.');
        }

        state.localStream = stream;
        ensureRemoteAudio();
        return stream;
    })();

    try {
        return await state.micPromise;
    } finally {
        state.micPromise = null;
    }
}

function stopMicrophone() {
    if (!state.localStream) return;
    state.localStream.getTracks().forEach((track) => {
        try { track.stop(); } catch { /* ignore */ }
    });
    state.localStream = null;
}

/* ------------------------------------------------------------------ */
/* Tones                                                               */
/* ------------------------------------------------------------------ */

function audioContext() {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;

    if (!state.audioCtx) state.audioCtx = new Ctx();
    if (state.audioCtx.state === 'suspended') {
        state.audioCtx.resume().catch(() => {});
    }

    return state.audioCtx;
}

function startTone(kind) {
    if (!state.ringtoneEnabled) return;
    const ctx = audioContext();
    if (!ctx) return;

    stopTones();

    const volume = Math.max(0, Math.min(1, state.ringtoneVolume / 100)) * 0.12;
    const ringback = kind === 'ringback';
    const pattern = ringback ? [[440, 0.8]] : [[880, 0.34], [660, 0.34]];
    const period = ringback ? 3 : 1.7;

    const play = () => {
        let offset = 0;
        pattern.forEach(([frequency, duration]) => {
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            oscillator.connect(gain);
            gain.connect(ctx.destination);

            const at = ctx.currentTime + offset;
            const level = Math.max(0.0001, volume);
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(level, at + 0.02);
            gain.gain.setValueAtTime(level, at + Math.max(0.03, duration - 0.05));
            gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);

            oscillator.start(at);
            oscillator.stop(at + duration + 0.02);
            offset += duration + 0.14;
        });
    };

    play();
    state.toneTimer = setInterval(play, period * 1000);
}

function stopTones() {
    if (state.toneTimer) {
        clearInterval(state.toneTimer);
        state.toneTimer = null;
    }
}

/* ------------------------------------------------------------------ */
/* Peer connection                                                     */
/* ------------------------------------------------------------------ */

function resetPeer() {
    if (state.pc) {
        try {
            state.pc.onicecandidate = null;
            state.pc.ontrack = null;
            state.pc.onconnectionstatechange = null;
            state.pc.oniceconnectionstatechange = null;
            state.pc.close();
        } catch { /* ignore */ }
        state.pc = null;
    }

    state.pendingIce = [];
}

async function preparePeerConnection() {
    if (state.pc) return state.pc;
    if (state.pcPromise) return state.pcPromise;

    state.pcPromise = (async () => {
        const stream = await getMicrophone();

        // The call may have ended while the microphone prompt was open.
        if (!state.call || !['connecting', 'active', 'reconnecting'].includes(store()?.status)) {
            stopMicrophone();
            return null;
        }

        if (state.pc) return state.pc;

        const pc = new RTCPeerConnection({
            iceServers: state.iceServers.length ? state.iceServers : FALLBACK_ICE,
            iceCandidatePoolSize: 4,
        });

        state.pc = pc;
        stream.getAudioTracks().forEach((track) => pc.addTrack(track, stream));

        pc.onicecandidate = (event) => {
            if (!event.candidate || !state.call) return;
            sendSignal('ice-candidate', event.candidate.toJSON()).catch(() => {});
        };

        pc.ontrack = (event) => {
            const audio = ensureRemoteAudio();
            audio.srcObject = event.streams[0] || new MediaStream([event.track]);
            audio.play().catch(() => {});
        };

        pc.onconnectionstatechange = () => {
            if (!state.call) return;
            if (pc.connectionState === 'connected') {
                onConnected();
            } else if (pc.connectionState === 'failed') {
                onConnectionTrouble('failed');
            } else if (pc.connectionState === 'disconnected') {
                onConnectionTrouble('disconnected');
            }
        };

        pc.oniceconnectionstatechange = () => {
            if (!state.call) return;
            if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
                onConnected();
            } else if (pc.iceConnectionState === 'failed') {
                onConnectionTrouble('failed');
            }
        };

        return pc;
    })();

    try {
        return await state.pcPromise;
    } finally {
        state.pcPromise = null;
    }
}

/**
 * SDP is ASCII, but CRLF line endings can be rewritten by transports on the
 * way to the other tab. Carrying it as base64 keeps the session description
 * byte-for-byte identical through JSON/broadcast layers.
 */
function encodeSdp(sdp) {
    return btoa(String(sdp));
}

function decodeSdp(encoded) {
    return atob(String(encoded));
}

function sessionDescription(signal) {
    return new RTCSessionDescription({
        type: signal.type,
        sdp: decodeSdp(signal.sdp),
    });
}

async function createOffer() {
    if (!state.pc) await preparePeerConnection();
    if (!state.pc) return;

    const offer = await state.pc.createOffer({ offerToReceiveAudio: true });
    await state.pc.setLocalDescription(offer);
    await sendSignal('offer', { type: offer.type, sdp: encodeSdp(offer.sdp) });
}

async function flushIce() {
    if (!state.pc || !state.pc.remoteDescription) return;

    const pending = state.pendingIce.splice(0);
    for (const candidate of pending) {
        try { await state.pc.addIceCandidate(candidate); } catch { /* ignore */ }
    }
}

async function handleSignal(type, event) {
    const call = event.call;
    if (!state.call || !call || Number(call.id) !== Number(state.call.id)) return;
    if (event.to_tab_id && event.to_tab_id !== state.tabId) return;

    try {
        if (!state.pc) await preparePeerConnection();
        if (!state.pc) return;

        if (type === 'offer') {
            if (state.role !== 'callee') return;
            await state.pc.setRemoteDescription(sessionDescription(event.signal));
            await flushIce();
            const answer = await state.pc.createAnswer();
            await state.pc.setLocalDescription(answer);
            await sendSignal('answer', { type: answer.type, sdp: encodeSdp(answer.sdp) });
            return;
        }

        if (type === 'answer') {
            if (state.role !== 'caller') return;
            await state.pc.setRemoteDescription(sessionDescription(event.signal));
            await flushIce();
            return;
        }

        if (type === 'ice-candidate') {
            if (state.pc.remoteDescription) {
                await state.pc.addIceCandidate(event.signal).catch(() => {});
            } else {
                state.pendingIce.push(event.signal);
            }
        }
    } catch (error) {
        console.warn('[call] signal error', type, error?.name, error?.message);
        // A bad/expired SDP should never leave the UI stuck in connecting.
        if (['connecting', 'active', 'reconnecting'].includes(store()?.status)) {
            onConnectionTrouble('failed');
        }
    }
}

async function sendSignal(type, payload) {
    if (!state.call) return;

    const callId = state.call.id;
    let lastError = null;

    // Trickle ICE fires many requests at once. A transient 5xx/429 (for
    // example a locked SQLite cache during another write) must never lose
    // an offer or answer, so retry those while keeping client errors final.
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            await request(`/api/calls/${callId}/signal`, {
                method: 'POST',
                body: JSON.stringify({ type, payload }),
            });
            return;
        } catch (error) {
            lastError = error;
            if (error.status && error.status < 500 && error.status !== 429) break;
            if (attempt < 2) {
                await new Promise((resolve) => setTimeout(resolve, 150 * (attempt + 1)));
            }
        }
    }

    throw lastError;
}

function onConnected() {
    if (!state.call) return;

    if (!state.startedAt) state.startedAt = Date.now();
    patch({ status: 'active', startedAt: state.startedAt, error: null });
    stopTones();
    clearTimeout(state.answerTimer);
    state.answerTimer = null;
    clearTimeout(state.reconnectTimer);
    state.reconnectTimer = null;
    startDurationTimer();
    startStatsTimer();
}

function onConnectionTrouble(kind) {
    if (!state.call) return;

    const current = store()?.status;
    if (!['connecting', 'active', 'reconnecting'].includes(current)) return;

    patch({
        status: 'reconnecting',
        quality: 'poor',
        error: kind === 'failed'
            ? 'Connection lost. Trying to reconnect…'
            : null,
    });

    if (!state.reconnectTimer) {
        state.reconnectTimer = setTimeout(() => {
            if (state.call && store()?.status === 'reconnecting') {
                endCall('network');
            }
        }, RECONNECT_TIMEOUT);
    }
}

function retryConnection() {
    if (!state.call || !state.pc) return;

    // Signaling can reconnect while the peer connection stayed healthy.
    // Never tear down working audio just because the socket blinked.
    if (state.pc.connectionState === 'connected'
        || state.pc.iceConnectionState === 'connected'
        || state.pc.iceConnectionState === 'completed') {
        onConnected();
        return;
    }

    patch({ status: 'reconnecting', error: null });

    if (state.role === 'caller') {
        try { state.pc.restartIce(); } catch { /* ignore */ }
        createOffer().catch(() => {});
    }
}

/* ------------------------------------------------------------------ */
/* Timer / quality                                                     */
/* ------------------------------------------------------------------ */

function updateElapsed() {
    if (!state.startedAt) return;
    patch({ elapsedSeconds: Math.max(0, Math.floor((Date.now() - state.startedAt) / 1000)) });
}

function startDurationTimer() {
    clearInterval(state.durationTimer);
    updateElapsed();
    state.durationTimer = setInterval(updateElapsed, 1000);
}

async function updateQuality() {
    if (!state.pc) return;

    try {
        const stats = await state.pc.getStats();
        let inbound = null;
        let pair = null;

        stats.forEach((report) => {
            if (report.type === 'inbound-rtp' && (report.kind === 'audio' || report.mediaType === 'audio')) {
                inbound = report;
            }
            if (report.type === 'candidate-pair' && report.state === 'succeeded') {
                pair = report;
            }
        });

        let quality = 'good';
        if (inbound) {
            const lost = Number(inbound.packetsLost || 0);
            const received = Number(inbound.packetsReceived || 0);
            const ratio = received > 0 ? lost / (lost + received) : 0;
            if (ratio > 0.08) quality = 'poor';
            else if (ratio > 0.02) quality = 'fair';
        }

        const rtt = Number(pair?.currentRoundTripTime || 0);
        if (rtt > 0.4) quality = 'poor';
        else if (rtt > 0.2 && quality === 'good') quality = 'fair';

        patch({ quality });
    } catch { /* stats are best effort */ }
}

function startStatsTimer() {
    clearInterval(state.statsTimer);
    updateQuality();
    state.statsTimer = setInterval(updateQuality, 3000);
}

/* ------------------------------------------------------------------ */
/* Local lifecycle                                                     */
/* ------------------------------------------------------------------ */

function clearCallTimers() {
    clearTimeout(state.ringTimer);
    clearTimeout(state.answerTimer);
    clearTimeout(state.reconnectTimer);
    clearTimeout(state.resetTimer);
    state.ringTimer = null;
    state.answerTimer = null;
    state.reconnectTimer = null;
    state.resetTimer = null;
    clearInterval(state.durationTimer);
    clearInterval(state.statsTimer);
    state.durationTimer = null;
    state.statsTimer = null;
}

function cleanupMedia() {
    stopMicrophone();
    resetPeer();
    if (state.remoteAudio) {
        try { state.remoteAudio.srcObject = null; } catch { /* ignore */ }
    }
    clearCallTimers();
    stopTones();
}

function scheduleReset(delay = 4000) {
    clearTimeout(state.resetTimer);
    const expectedCallId = state.call?.id ?? null;

    state.resetTimer = setTimeout(() => {
        if (store()?.status !== 'ended') return;
        if ((state.call?.id ?? null) !== expectedCallId) return;
        reset();
    }, delay);
}

function finishLocal(message, status = 'ended') {
    cleanupMedia();
    patch({ status, error: message, quality: null });
    scheduleReset();
}

function reset() {
    clearCallTimers();
    cleanupMedia();
    state.call = null;
    state.role = null;
    state.peer = null;
    state.startedAt = null;
    state.ringingAcked = null;
    patch({
        status: 'idle',
        peer: null,
        isMuted: false,
        startedAt: null,
        elapsedSeconds: 0,
        error: null,
        quality: null,
    });
}

function peerFromCall(call) {
    const mine = myId();
    const user = Number(call.caller_id) === mine ? call.callee : call.caller;
    return user ? {
        id: Number(user.id),
        name: user.name,
        username: user.username,
        avatar: user.avatar,
    } : null;
}

/* ------------------------------------------------------------------ */
/* Public actions                                                      */
/* ------------------------------------------------------------------ */

async function startCall(peer) {
    if (!peer?.id) return;
    const current = store()?.status;
    if (current && current !== 'idle' && current !== 'ended') return;

    clearTimeout(state.resetTimer);
    state.resetTimer = null;

    state.role = 'caller';
    state.peer = {
        id: Number(peer.id),
        name: peer.name || 'Unknown',
        username: peer.username || '',
        avatar: peer.avatar || null,
    };
    state.call = null;
    state.startedAt = null;
    state.ringingAcked = null;

    patch({
        status: 'calling',
        peer: state.peer,
        isMuted: false,
        startedAt: null,
        elapsedSeconds: 0,
        error: null,
        quality: null,
    });

    if (!isSecure()) {
        patch({ status: 'ended', error: HTTPS_MESSAGE });
        scheduleReset(6000);
        return;
    }

    await loadConfig();
    startTone('ringback');

    try {
        await getMicrophone();

        const data = await request('/api/calls/invite', {
            method: 'POST',
            body: JSON.stringify({
                user_id: state.peer.id,
                conversation_id: peer.conversationId || null,
                tab_id: state.tabId,
            }),
        });

        state.call = data.call;
        if (store()?.status === 'calling') {
            patch({ status: 'calling' });
        }

        state.ringTimer = setTimeout(() => {
            if (!state.call) return;
            const status = store()?.status;
            if (status === 'calling' || status === 'ringing') {
                request(`/api/calls/${state.call.id}/cancel`, { method: 'POST' }).catch(() => {});
                finishLocal('No answer.');
            }
        }, RING_TIMEOUT);
    } catch (error) {
        finishLocal(friendlyError(error));
    }
}

async function acceptCall() {
    if (!state.call || store()?.status !== 'incoming') return;

    if (!isSecure()) {
        rejectCall('declined');
        return;
    }

    await loadConfig();
    stopTones();

    try {
        await getMicrophone();

        const data = await request(`/api/calls/${state.call.id}/accept`, {
            method: 'POST',
            body: JSON.stringify({ tab_id: state.tabId }),
        });

        state.call = data.call;
        state.role = 'callee';
        state.startedAt = null;
        clearTimeout(state.ringTimer);
        state.ringTimer = null;
        patch({ status: 'connecting', error: null, quality: null });

        await preparePeerConnection();

        clearTimeout(state.answerTimer);
        state.answerTimer = setTimeout(() => {
            if (state.call && ['connecting', 'reconnecting'].includes(store()?.status)) {
                endCall('network');
            }
        }, RECONNECT_TIMEOUT);
    } catch (error) {
        // Another tab answered first, or the ring timed out. The winning tab
        // owns the call; this one just goes back to idle.
        if (error.status === 409 || error.status === 410) {
            reset();
            return;
        }

        // Microphone denied: the call was never accepted. Tell the caller.
        try {
            await request(`/api/calls/${state.call.id}/reject`, { method: 'POST' });
        } catch { /* ignore */ }

        finishLocal(friendlyError(error), 'ended');
        scheduleReset(6000);
    }
}

function rejectCall() {
    if (!state.call) {
        reset();
        return;
    }

    request(`/api/calls/${state.call.id}/reject`, { method: 'POST' }).catch(() => {});
    finishLocal('Call declined.');
}

function cancelCall() {
    if (!state.call) {
        reset();
        return;
    }

    request(`/api/calls/${state.call.id}/cancel`, { method: 'POST' }).catch(() => {});
    finishLocal('Call cancelled.');
}

function endCall(reason = 'hangup') {
    if (!state.call) {
        reset();
        return;
    }

    const callId = state.call.id;
    request(`/api/calls/${callId}/end`, {
        method: 'POST',
        body: JSON.stringify({ reason }),
    }).catch(() => {});

    finishLocal(reason === 'network' ? 'Connection lost. Call ended.' : 'Call ended.');
}

function toggleMute() {
    if (!state.localStream) return;

    const tracks = state.localStream.getAudioTracks();
    if (!tracks.length) return;

    const shouldMute = !tracks.every((track) => track.enabled === false);
    tracks.forEach((track) => { track.enabled = !shouldMute; });
    patch({ isMuted: shouldMute });
}

async function setOutputDevice(deviceId) {
    const audio = ensureRemoteAudio();
    if (typeof audio.setSinkId !== 'function') return false;

    try {
        await audio.setSinkId(deviceId || '');
        try {
            localStorage.setItem(`incognito-speaker-device-${myId()}`, deviceId || '');
        } catch { /* ignore */ }
        return true;
    } catch {
        return false;
    }
}

/** Settings changes apply to the next ring without a page reload. */
function setRingtone(enabled, volume) {
    state.ringtoneEnabled = enabled !== false;
    const next = Number(volume);
    if (Number.isFinite(next)) state.ringtoneVolume = Math.max(0, Math.min(100, next));
    if (!state.ringtoneEnabled) stopTones();
}

/* ------------------------------------------------------------------ */
/* Socket / browser lifecycle                                          */
/* ------------------------------------------------------------------ */

async function loadConfig() {
    if (state.configLoaded) return;
    if (state.configPromise) return state.configPromise;

    state.configPromise = (async () => {
        try {
            const data = await request('/api/calls/config');
            if (Array.isArray(data?.ice_servers) && data.ice_servers.length) {
                state.iceServers = data.ice_servers;
            }
            state.ringtoneEnabled = data?.ringtone !== false;
            const volume = Number(data?.ringtone_volume);
            if (Number.isFinite(volume)) state.ringtoneVolume = Math.max(0, Math.min(100, volume));
        } catch {
            state.iceServers = FALLBACK_ICE;
        } finally {
            state.configLoaded = true;
        }
    })();

    return state.configPromise;
}

function onInvite(event) {
    const call = event?.call;
    if (!call?.id) return;

    if (state.call && Number(state.call.id) === Number(call.id)) return;

    const current = store()?.status;
    if (current && current !== 'idle' && current !== 'ended') return;

    clearTimeout(state.resetTimer);
    state.resetTimer = null;
    state.call = call;
    state.role = 'callee';
    state.peer = peerFromCall(call);
    state.startedAt = null;
    state.ringingAcked = null;

    patch({
        status: 'incoming',
        peer: state.peer,
        isMuted: false,
        startedAt: null,
        elapsedSeconds: 0,
        error: null,
        quality: null,
    });

    ensureRemoteAudio();
    startTone('ringtone');

    state.ringTimer = setTimeout(() => {
        if (!state.call) return;
        request(`/api/calls/${state.call.id}/timeout`, { method: 'POST' }).catch(() => {});
        finishLocal('Missed audio call.');
    }, RING_TIMEOUT);

    if (state.ringingAcked !== call.id) {
        state.ringingAcked = call.id;
        request(`/api/calls/${call.id}/ringing`, {
            method: 'POST',
            body: JSON.stringify({ tab_id: state.tabId }),
        }).catch(() => {});
    }
}

function onRinging(event) {
    const call = event?.call;
    if (!call?.id) return;

    if (!state.call && store()?.status === 'calling') {
        state.call = call;
        state.role = 'caller';
        state.peer = peerFromCall(call);
    }

    if (!state.call || Number(state.call.id) !== Number(call.id)) return;
    if (state.role !== 'caller') return;

    if (store()?.status === 'calling') {
        patch({ status: 'ringing' });
    }
}

function onAccept(event) {
    const call = event?.call;
    if (!call?.id) return;

    if (!state.call && store()?.status === 'calling') {
        if (call.caller_tab_id && call.caller_tab_id !== state.tabId) return;
        state.call = call;
        state.role = 'caller';
        state.peer = peerFromCall(call);
    }

    if (!state.call || Number(state.call.id) !== Number(call.id)) return;

    const acceptedTab = event.accepted_tab_id;

    if (state.role === 'callee') {
        if (acceptedTab && acceptedTab !== state.tabId) {
            // Another tab answered; this one must stop ringing silently.
            cleanupMedia();
            state.call = null;
            state.role = null;
            state.peer = null;
            patch({
                status: 'idle',
                peer: null,
                isMuted: false,
                startedAt: null,
                elapsedSeconds: 0,
                error: null,
                quality: null,
            });
            return;
        }

        state.call = call;
        clearTimeout(state.ringTimer);
        state.ringTimer = null;
        stopTones();
        patch({ status: 'connecting' });
        preparePeerConnection().catch(() => onConnectionTrouble('failed'));
        return;
    }

    if (call.caller_tab_id && call.caller_tab_id !== state.tabId) return;

    state.call = call;
    clearTimeout(state.ringTimer);
    state.ringTimer = null;
    stopTones();
    patch({ status: 'connecting' });

    preparePeerConnection()
        .then(() => createOffer())
        .catch((error) => {
            patch({ status: 'ended', error: friendlyError(error) });
            scheduleReset();
        });
}

function onReject(event) {
    if (!state.call || Number(state.call.id) !== Number(event?.call?.id)) return;
    finishLocal('Call declined.');
}

function onCancel(event) {
    if (!state.call || Number(state.call.id) !== Number(event?.call?.id)) return;

    if (state.role === 'callee') {
        finishLocal('Missed audio call.');
    } else {
        finishLocal('Call cancelled.');
    }
}

function onEnd(event) {
    if (!state.call || Number(state.call.id) !== Number(event?.call?.id)) return;

    const call = event.call || {};
    let message = 'Call ended.';
    if (call.status === 'missed') {
        message = event.reason === 'timeout'
            ? 'No answer.'
            : 'Missed audio call.';
    } else if (call.status === 'declined') {
        message = 'Call declined.';
    } else if (event.reason === 'network' || event.reason === 'disconnect') {
        message = 'Connection lost. Call ended.';
    }

    finishLocal(message);
}

function onBusy(event) {
    // Busy/unavailable is only meaningful to the tab that placed the call.
    // Other tabs of the caller are idle and must stay quiet.
    if (store()?.status !== 'calling') return;

    const call = event?.call;
    if (state.call && call?.id && Number(state.call.id) !== Number(call.id)) return;

    if (!state.call && call) {
        state.call = call;
        state.role = 'caller';
        state.peer = peerFromCall(call) || state.peer;
    }

    const unavailable = event?.reason === 'unavailable';
    cleanupMedia();
    patch({
        status: 'ended',
        peer: state.peer,
        error: unavailable ? 'User is unavailable.' : 'User is busy.',
        quality: null,
    });
    scheduleReset(5000);
}

function onConnectionState(states) {
    if (!state.call) return;

    // The WebSocket and the peer-to-peer audio are independent. A dropped
    // socket does not mean the call is broken, so only use the reconnect
    // event to recover a UI that is already waiting to reconnect.
    if (states.current !== 'connected') return;

    if (store()?.status === 'reconnecting') {
        retryConnection();
    }
}

function onPageHide() {
    if (!state.call) {
        cleanupMedia();
        return;
    }

    const status = store()?.status;
    const url = (action) => `/api/calls/${state.call.id}/${action}`;

    if (['active', 'connecting', 'reconnecting'].includes(status)) {
        beacon(url('end'), { reason: 'disconnect' });
    } else if (status === 'incoming') {
        // Only this ringing tab disappears; other tabs may still ring.
        beacon(url('leave'), { tab_id: state.tabId });
    } else if (status === 'calling' || status === 'ringing') {
        beacon(url('cancel'));
    }

    cleanupMedia();
}

/** A back/forward-cache restore has no live WebRTC objects anymore. */
function onPageShow(event) {
    if (event.persisted && state.call) reset();
}

function bindEcho() {
    if (state.bound) return;

    const echo = window.Echo;
    if (!echo) {
        setTimeout(bindEcho, 300);
        return;
    }

    state.bound = true;
    state.channel = echo.private(`users.${myId()}`);

    state.channel
        .listen('.call:invite', onInvite)
        .listen('.call:ringing', onRinging)
        .listen('.call:accept', onAccept)
        .listen('.call:reject', onReject)
        .listen('.call:cancel', onCancel)
        .listen('.call:busy', onBusy)
        .listen('.call:offer', (event) => handleSignal('offer', event))
        .listen('.call:answer', (event) => handleSignal('answer', event))
        .listen('.call:ice-candidate', (event) => handleSignal('ice-candidate', event))
        .listen('.call:end', onEnd);

    const connection = echo.connector?.pusher?.connection;
    if (connection) {
        state.connectionHandler = onConnectionState;
        connection.bind('state_change', state.connectionHandler);
    }

    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);
}

function init() {
    if (state.initialized) return;
    state.initialized = true;
    bindEcho();
    loadConfig();
}

function destroy() {
    window.removeEventListener('pagehide', onPageHide);
    window.removeEventListener('pageshow', onPageShow);

    if (state.channel) {
        [
            '.call:invite', '.call:ringing', '.call:accept', '.call:reject',
            '.call:cancel', '.call:busy', '.call:offer', '.call:answer',
            '.call:ice-candidate', '.call:end',
        ].forEach((event) => {
            try { state.channel.stopListening(event); } catch { /* ignore */ }
        });
        state.channel = null;
    }

    const connection = window.Echo?.connector?.pusher?.connection;
    if (connection && state.connectionHandler) {
        try { connection.unbind('state_change', state.connectionHandler); } catch { /* ignore */ }
    }
    state.connectionHandler = null;

    cleanupMedia();
    state.bound = false;
    state.initialized = false;
}

/**
 * Read-only, serializable snapshot used by the development-only diagnostics
 * panel. Never returns native WebRTC objects to Alpine.
 */
export async function getDiagnostics() {
    const connection = window.Echo?.connector?.pusher?.connection;

    let micPermission = 'unknown';
    try {
        const result = await navigator.permissions?.query?.({ name: 'microphone' });
        micPermission = result?.state || 'unknown';
    } catch { /* browsers without the query keep 'unknown' */ }

    let candidateType = null;
    const pc = state.pc;
    if (pc && pc.signalingState !== 'closed') {
        try {
            const report = await pc.getStats();
            let pairId = null;
            report.forEach((entry) => {
                if (entry.type === 'transport' && entry.selectedCandidatePairId) {
                    pairId = entry.selectedCandidatePairId;
                }
            });
            if (!pairId) {
                report.forEach((entry) => {
                    if (entry.type === 'candidate-pair' && (entry.nominated || entry.state === 'succeeded')) {
                        pairId = pairId || entry.id;
                    }
                });
            }
            const pair = pairId ? report.get(pairId) : null;
            if (pair) {
                const local = pair.localCandidateId ? report.get(pair.localCandidateId) : null;
                const remote = pair.remoteCandidateId ? report.get(pair.remoteCandidateId) : null;
                candidateType = [local?.candidateType, remote?.candidateType]
                    .filter(Boolean).join(' / ') || null;
            }
        } catch { /* stats are best-effort */ }
    }

    // Build the URL from Echo's own config rather than a possibly stale
    // socket object, so the panel reflects the active configuration.
    const echoConfig = window.Echo?.connector?.pusher?.config;
    const echoUrl = echoConfig
        ? `${echoConfig.forceTLS ? 'wss' : 'ws'}://${echoConfig.wsHost}:${echoConfig.wsPort || echoConfig.wssPort}${echoConfig.path || '/app'}`
        : null;

    return {
        secureContext: !!window.isSecureContext,
        micPermission,
        echoState: connection?.state || 'unavailable',
        echoUrl,
        iceState: pc?.iceConnectionState || 'none',
        peerState: pc?.connectionState || 'none',
        candidateType,
        callStatus: store()?.status || 'idle',
    };
}

export const callManager = {
    init,
    destroy,
    startCall,
    acceptCall,
    rejectCall,
    cancelCall,
    endCall,
    toggleMute,
    setOutputDevice,
    setRingtone,
    retryConnection,
    reset,
    loadConfig,
    getDiagnostics,
    get tabId() { return state.tabId; },
};
