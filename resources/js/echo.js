import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content')?.trim() || '';

const secure = window.location.protocol === 'https:';
const host = window.location.hostname;
const pagePort = window.location.port || (secure ? '443' : '80');
const reverbPort = Number(meta('reverb-port') || pagePort);
const reverbPath = meta('reverb-path') || '/app';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: meta('reverb-key') || import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    path: reverbPath,

    auth: {
        headers: {
            'X-CSRF-TOKEN': meta('csrf-token'),
        },
    },
});

window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log(`[WS] connection: ${states.previous} → ${states.current}`);
});
