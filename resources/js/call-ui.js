import './echo';
import Alpine from 'alpinejs';
import '../css/calls.css';
import { registerCallStore } from './calls/store';
import { registerCallComponents } from './calls/alpine';
import { callManager } from './calls/callManager';

/*
 * Shared call bootstrap for every authenticated page (messenger + settings).
 * Importing this module twice is harmless because Alpine registration is
 * guarded by the store lookup and the callManager has its own init guard.
 */
if (!window.__incognitoCallUiBooted) {
    window.__incognitoCallUiBooted = true;
    window.Alpine = Alpine;
    registerCallStore(Alpine);
    registerCallComponents(Alpine);
    Alpine.start();
}

callManager.init();

// The Settings page uses plain inline JS, so expose the engine for live
// ringtone changes. It still never exposes WebRTC objects.
window.incognitoCalls = callManager;

function heartbeat() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const userId = document.querySelector('meta[name="user-id"]')?.content;
    if (!csrf || !userId) return;

    fetch('/api/presence/heartbeat', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
    }).catch(() => {});
}

// The messenger already runs its own 30s presence heartbeat in app.js.
// Only add one on pages that do not have that controller (e.g. Settings).
const messengerOwnsHeartbeat = !!document.getElementById('conversation-list');

if (!messengerOwnsHeartbeat && document.querySelector('meta[name="user-id"]')) {
    heartbeat();
    setInterval(heartbeat, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') heartbeat();
    });
}
