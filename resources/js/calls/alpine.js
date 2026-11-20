import { callManager, getDiagnostics } from './callManager';

const formatElapsed = (seconds) => {
    const total = Math.max(0, Number(seconds) || 0);
    const minutes = Math.floor(total / 60);
    const rest = total % 60;
    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
};

const fallbackAvatar = (name, key) => {
    const display = String(name ?? '').trim() || '?';
    const seed = String(key ?? display).trim() || display;
    const initial = (Array.from(display)[0] ?? '?').toUpperCase().replace(/[<>&"']/g, '');
    const palette = ['#0ea5e9', '#8b5cf6', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#f59e0b'];
    let hash = 0;
    for (const char of seed) hash = (hash * 31 + char.codePointAt(0)) >>> 0;
    const color = palette[hash % palette.length];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="${color}"/><text x="48" y="61" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="44" font-weight="600" fill="#fff">${initial}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
};

export function registerCallComponents(Alpine) {
    Alpine.data('callOverlay', () => ({
        outputs: [],
        speakerId: '',

        init() {
            try {
                this.speakerId = localStorage.getItem(`incognito-speaker-device-${document.querySelector('meta[name="user-id"]')?.content || 0}`) || '';
            } catch {}

            this.refreshOutputs();

            this.$watch('$store.call.status', (status) => {
                if (status === 'incoming') {
                    this.$nextTick(() => this.$refs.accept?.focus());
                }
                if (status === 'active') {
                    this.refreshOutputs();
                }
            });

            this._onKeydown = (event) => this.onEscape(event);
            window.addEventListener('keydown', this._onKeydown);
        },

        destroy() {
            window.removeEventListener('keydown', this._onKeydown);
        },

        fullScreen() {
            return ['incoming', 'calling', 'ringing', 'connecting', 'ended'].includes(this.$store.call.status);
        },

        statusLabel() {
            switch (this.$store.call.status) {
                case 'incoming': return 'Incoming audio call';
                case 'calling': return 'Calling…';
                case 'ringing': return 'Ringing…';
                case 'connecting': return 'Connecting…';
                case 'active': return 'Audio call in progress';
                case 'reconnecting': return 'Reconnecting…';
                case 'ended': return this.$store.call.error || 'Call ended';
                default: return '';
            }
        },

        elapsedLabel() {
            return formatElapsed(this.$store.call.elapsedSeconds);
        },

        avatarSrc() {
            const peer = this.$store.call.peer;
            return peer?.avatar || fallbackAvatar(peer?.name, peer?.username || peer?.id);
        },

        avatarFallback() {
            const peer = this.$store.call.peer;
            return fallbackAvatar(peer?.name, peer?.username || peer?.id);
        },

        qualityLabel() {
            const quality = this.$store.call.quality;
            if (quality === 'poor') return 'Poor connection';
            if (quality === 'fair') return 'Fair connection';
            return 'Good connection';
        },

        outputSupported() {
            return typeof HTMLMediaElement !== 'undefined'
                && typeof HTMLMediaElement.prototype.setSinkId === 'function';
        },

        async refreshOutputs() {
            if (!navigator.mediaDevices?.enumerateDevices) return;

            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                this.outputs = devices
                    .filter((device) => device.kind === 'audiooutput')
                    .map((device, index) => ({
                        id: device.deviceId,
                        label: device.label || `Speaker ${index + 1}`,
                    }));
            } catch {
                this.outputs = [];
            }
        },

        async selectOutput() {
            await callManager.setOutputDevice(this.speakerId);
        },

        accept() {
            this.$store.call.acceptCall();
        },

        decline() {
            this.$store.call.rejectCall();
        },

        end() {
            this.$store.call.endCall();
        },

        cancel() {
            this.$store.call.cancelCall();
        },

        retry() {
            this.$store.call.retryConnection();
        },

        retryCall() {
            const peer = this.$store.call.peer;
            this.$store.call.reset();
            if (peer?.id) this.$store.call.startCall(peer);
        },

        close() {
            this.$store.call.reset();
        },

        onEscape(event) {
            if (event.key !== 'Escape') return;

            const status = this.$store.call.status;
            if (status === 'incoming') {
                event.preventDefault();
                this.decline();
            } else if (status === 'calling' || status === 'ringing') {
                event.preventDefault();
                this.cancel();
            }
        },
    }));

    Alpine.data('callDiagnostics', () => ({
        enabled: false,
        open: false,
        data: {},
        timer: null,

        init() {
            const flag = document.querySelector('meta[name="app-debug"]')?.content;
            this.enabled = flag === '1';
            if (!this.enabled) return;
            this.refresh();
            this.timer = setInterval(() => this.refresh(), 1000);
        },

        destroy() {
            if (this.timer) clearInterval(this.timer);
        },

        async refresh() {
            this.data = await getDiagnostics();
        },

        echoUrl() {
            return this.data.echoUrl
                || `${window.location.protocol === 'https:' ? 'wss' : 'ws'}://${window.location.host}/app`;
        },
    }));

    Alpine.data('chatCallButton', () => ({
        peer: null,
        online: false,
        blocked: false,
        secure: window.isSecureContext
            || location.hostname === 'localhost'
            || location.hostname === '127.0.0.1',

        init() {
            this._onPeer = (event) => this.applyPeer(event.detail);
            window.addEventListener('chat-peer-changed', this._onPeer);

            if (window.__incognitoChatPeer !== undefined) {
                this.applyPeer(window.__incognitoChatPeer);
            }
        },

        destroy() {
            window.removeEventListener('chat-peer-changed', this._onPeer);
        },

        applyPeer(detail) {
            this.peer = detail?.peer || null;
            this.online = !!detail?.online;
            this.blocked = !!detail?.blocked;
        },

        disabled() {
            return this.$store.call.status !== 'idle'
                || !this.peer
                || !this.online
                || this.blocked
                || !this.secure;
        },

        buttonTitle() {
            if (!this.secure) return 'Calls need HTTPS';
            if (this.blocked) return 'You cannot call this user';
            if (!this.peer) return 'Audio call';
            if (!this.online) return 'User is offline';
            if (this.$store.call.status !== 'idle') return 'You are already in a call';
            return 'Audio call';
        },

        call() {
            if (this.disabled()) return;
            this.$store.call.startCall(this.peer);
        },
    }));
}
