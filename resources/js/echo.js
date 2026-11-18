import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/*
 * Laravel Echo is a thin wrapper around a raw WebSocket connection.
 * Despite the "pusher" import, nothing is sent to pusher.com — with
 * broadcaster: 'reverb' all traffic goes to our local Reverb server
 * (ws://localhost:8080, credentials from .env via Vite).
 */
/*
 * The WebSocket host is derived from window.location.hostname instead of
 * being baked in, so the SAME build works when the page is opened from
 * localhost, from another machine via the server's LAN IP, or behind a
 * domain — the browser always connects to whatever host served the page.
 * (Baking VITE_REVERB_HOST=localhost would break LAN access: the other
 * machine's browser would try to open the WebSocket against itself.)
 */
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: window.location.protocol === 'https:',
    enabledTransports: ['ws', 'wss'],

    // Private/presence subscriptions need CSRF protection because the
    // auth handshake is a POST to /broadcasting/auth using the session.
    auth: {
        headers: {
            'X-CSRF-TOKEN': document
                .querySelector('meta[name="csrf-token"]')
                .getAttribute('content'),
        },
    },
});

// Log every connection state change so you can literally watch the
// WebSocket lifecycle in the browser console.
window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log(`[WS] connection: ${states.previous} → ${states.current}`);
});
