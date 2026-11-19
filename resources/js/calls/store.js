import { callManager } from './callManager';

/**
 * Alpine only sees plain, serializable UI state. Native WebRTC objects
 * (RTCPeerConnection, MediaStream, tracks, audio elements) live in the
 * callManager module and are never assigned to this store.
 */
export function registerCallStore(Alpine) {
    if (Alpine.store('call')) return;

    Alpine.store('call', {
        status: 'idle',
        peer: null,
        isMuted: false,
        startedAt: null,
        elapsedSeconds: 0,
        error: null,
        quality: null,

        startCall(peer) {
            callManager.startCall(peer);
        },

        acceptCall() {
            callManager.acceptCall();
        },

        rejectCall() {
            callManager.rejectCall();
        },

        cancelCall() {
            callManager.cancelCall();
        },

        endCall() {
            callManager.endCall('hangup');
        },

        toggleMute() {
            callManager.toggleMute();
        },

        retryConnection() {
            callManager.retryConnection();
        },

        reset() {
            callManager.reset();
        },
    });
}
