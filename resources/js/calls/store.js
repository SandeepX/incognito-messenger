import { callManager } from './callManager';

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
