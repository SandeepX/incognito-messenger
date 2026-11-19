import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content')?.trim() || '';

/*
 * The whole app is served from ONE origin. In LAN HTTPS mode Caddy serves
 * the Laravel app and proxies Reverb on that same origin. So the WebSocket
 * host, port and TLS are derived from the page that loaded this script —
 * nothing is baked in at build time and "localhost" is never assumed.
 *
 * The same bundle therefore works when opened from:
 *   - http://localhost:8000            (local dev, split ports)
 *   - http://192.168.x.y:8000          (LAN over plain HTTP)
 *   - https://192.168.x.y:8443         (Caddy single-origin HTTPS)
 *   - https://chat.example.com         (reverse proxy / domain)
 *
 * Split setups where Reverb listens on its own port (php artisan serve on
 * 8000 + php artisan reverb:start on 8080, with no proxy) can emit a
 * `reverb-port` meta tag from the server to override the port. An empty tag
 * (the default) means "use the same port that served the page".
 */
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
    // pusher-js performs the TLS upgrade on the "ws" transport when
    // forceTLS is true; "wss" is a separate transport used only in the
    // non-TLS strategy. Enabling both keeps HTTP and HTTPS deployments
    // working without a silent "no enabled transport" connection failure.
    enabledTransports: ['ws', 'wss'],
    path: reverbPath,

    // Private/presence channel authorization is a same-origin POST to
    // /broadcasting/auth that uses the Laravel session. The CSRF token is
    // rendered by the server into every authenticated page.
    auth: {
        headers: {
            'X-CSRF-TOKEN': meta('csrf-token'),
        },
    },
});

// Log every connection state change so the WebSocket lifecycle can be
// watched from the browser console (and by the dev diagnostics panel).
window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log(`[WS] connection: ${states.previous} → ${states.current}`);
});
