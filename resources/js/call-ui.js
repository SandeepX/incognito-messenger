import './echo';
import Alpine from 'alpinejs';
import '../css/calls.css';
import { registerCallStore } from './calls/store';
import { registerCallComponents } from './calls/alpine';
import { callManager } from './calls/callManager';

if (!window.__incognitoCallUiBooted) {
    window.__incognitoCallUiBooted = true;
    window.Alpine = Alpine;
    registerCallStore(Alpine);
    registerCallComponents(Alpine);
    Alpine.start();
}

callManager.init();

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

const messengerOwnsHeartbeat = !!document.getElementById('conversation-list');

if (!messengerOwnsHeartbeat && document.querySelector('meta[name="user-id"]')) {
    heartbeat();
    setInterval(heartbeat, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') heartbeat();
    });
}
