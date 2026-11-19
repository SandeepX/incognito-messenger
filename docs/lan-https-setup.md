# LAN HTTPS setup (single origin)

Audio calls use `getUserMedia`, which browsers only allow in a **secure
context**: `https://…`, or `http://localhost` / `http://127.0.0.1`. When other
devices open the app over the host's LAN IP (`http://192.168.x.y:8000`) the
microphone is blocked and calls fail. This guide puts the whole app behind one
HTTPS origin so calls, chat and Reverb all work from every device.

The setup uses [Caddy](https://caddyserver.com/) as a reverse proxy on
`https://<host-ip>:8443`:

```
browser  ── https://192.168.x.y:8443 ──> Caddy
                                          ├─ /app/*, /apps/*  -> Reverb 127.0.0.1:8080
                                          └─ everything else -> php artisan serve 127.0.0.1:8000
```

There is **one origin**, so `/broadcasting/auth`, `/api/calls/*`, the built
assets and the WebSocket connection are all same-origin. Browser Echo derives
`wsHost`, the port and TLS from `window.location`, so no host or port is baked
into the JavaScript and nothing is hardcoded to `localhost`.

---

## 1. Start Laravel and Reverb on loopback

```bash
# Terminal 1 — the app (only reachable through Caddy from other devices)
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 — Reverb (listens on all interfaces per REVERB_SERVER_HOST,
# but Caddy only exposes it through the proxy)
php artisan reverb:start --host=127.0.0.1 --port=8080
```

`php artisan serve` and `reverb:start` bind to loopback here; Caddy is the only
process exposed on the LAN.

## 2. Create a local certificate for the LAN IP with mkcert

Install mkcert (Debian/Ubuntu example):

```bash
sudo apt-get install -y libnss3-tools curl
curl -JLO "https://dl.filippo.io/mkcert/latest?for=linux/amd64"
chmod +x mkcert-v*-linux-amd64
sudo mv mkcert-v*-linux-amd64 /usr/local/bin/mkcert
mkcert -install
```

Generate a certificate that covers the current host IP (replace it with yours):

```bash
mkdir -p ~/certs
cd ~/certs
mkcert 192.168.1.10 localhost 127.0.0.1 ::1
# -> 192.168.1.10+4.pem  and  192.168.1.10+4-key.pem
```

## 3. Configure `.env`

All values are read from `.env`; nothing about the host is hardcoded in code.

```dotenv
APP_URL=https://192.168.1.10:8443

BROADCAST_CONNECTION=reverb

# Reverb server socket (what reverb:start binds to)
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Laravel -> Reverb publishing stays local (never via the LAN IP)
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Browser derives host/port/TLS from window.location; leave these empty
REVERB_BROWSER_PORT=
REVERB_BROWSER_PATH=

# Reverb compares only the HOSTNAME from the Origin header (no scheme/port).
# Comma-separated; use the bare LAN IP here.
REVERB_ALLOWED_ORIGINS=192.168.1.10

# Laravel must trust Caddy's X-Forwarded-* headers.
# With `php artisan serve` on the host this is 127.0.0.1. If Laravel runs in
# Docker and Caddy connects to a published port, use the docker network
# gateway IP instead (e.g. 172.20.0.1 — check `docker network inspect <net>`).
TRUSTED_PROXIES=127.0.0.1
# Optional: lock down hosts once the IP is stable (comma-separated)
TRUSTED_HOSTS=192.168.1.10
CORS_ALLOWED_ORIGINS=https://192.168.1.10:8443

# Session cookies must be Secure over HTTPS
SESSION_SECURE_COOKIE=true
```

Then refresh server caches so config is re-read:

```bash
php artisan config:clear && php artisan cache:clear
php artisan view:clear
```

## 4. Run Caddy with the shipped Caddyfile

```bash
export LAN_HOST=192.168.1.10
export TLS_CERT=/home/user/certs/192.168.1.10+4.pem
export TLS_KEY=/home/user/certs/192.168.1.10+4-key.pem
caddy run --config Caddyfile
```

`Caddyfile` at the repository root proxies `/app/*` and `/apps/*` to Reverb and
everything else to `php artisan serve`. The WebSocket handshake therefore
happens on the same HTTPS origin as the page, and `/broadcasting/auth` is a
normal same-origin POST with the session cookie and CSRF token.

> **Optional override for split setups:** if you are *not* using Caddy and
> Reverb runs on its own port (e.g. 8080) with no proxy, set
> `REVERB_BROWSER_PORT=8080` in `.env`. Leave it empty for the single-origin
> setup above.

## 5. Trust the mkcert root CA on the other devices

Browsers reject a self-signed certificate unless the CA that signed it is
trusted. Find the root CA on the host:

```bash
mkcert -CAROOT
# e.g. /home/user/.local/share/mkcert
# copy rootCA.pem to each device (USB, shared drive, local web server, ...)
```

- **Android:** Settings → Security → *Install from storage* → **CA
  certificate**, pick `rootCA.pem`.
- **iOS:** open `rootCA.pem` (AirDrop/email), then Settings → General → VPN &
  Device Management → install the profile; then Settings → General → About →
  Certificate Trust Settings → enable full trust for it.
- **macOS:** double-click `rootCA.pem`, add it to the *System* keychain and set
  "Always Trust".
- **Windows:** `certmgr.msc` → Trusted Root Certification Authorities →
  import `rootCA.pem`.

Without this step the page will show a certificate warning and `getUserMedia`
will not be available.

## 6. Verify

Open `https://192.168.1.10:8443` on the host and on another device, log in as
two different users and:

1. Check the browser console for `[WS] connection: … → connected`.
2. Confirm **Calls need HTTPS** is not shown on the call button tooltip.
3. Place a call; both sides should reach the active timer with two-way audio.

With `APP_DEBUG=true` a **Connection diagnostics** panel (bottom-left) shows
`isSecureContext`, microphone permission, Echo/Reverb state and URL, ICE
connection state and the selected candidate type. It disappears when
`APP_DEBUG=false`.

## 7. If the host IP changes

Update it in **all** of these places, then restart the services:

- the mkcert certificate (regenerate for the new IP, copy the new root CA to
  devices if it changed),
- `APP_URL`, `REVERB_ALLOWED_ORIGINS`, `TRUSTED_HOSTS`, `CORS_ALLOWED_ORIGINS`
  in `.env`,
- `LAN_HOST`, `TLS_CERT`, `TLS_KEY` environment variables for Caddy.

Nothing in `resources/js` needs to change — the browser always uses the host
that served the page.

## 8. Alternative: ngrok or cloudflared (no LAN certificate to distribute)

If distributing a root CA is inconvenient, expose the Caddy port through a
tunnel and use the public HTTPS URL instead. Keep Caddy running on `8443` and
then:

```bash
# ngrok
ngrok http 8443
# -> https://<random>.ngrok-free.app
```

```bash
# cloudflared (Caddy uses a self-signed cert, so skip verification on the hop
# from cloudflared to Caddy)
cloudflared tunnel --no-tls-verify --url https://127.0.0.1:8443
# -> https://<random>.trycloudflare.com
```

Then set `APP_URL` and `CORS_ALLOWED_ORIGINS` to the full public URL, but set
`REVERB_ALLOWED_ORIGINS` to just its hostname (e.g. `<random>.ngrok-free.app`).
Clear config cache and reload. The browser again connects to the
same origin, so the WebSocket and `/broadcasting/auth` keep working. These
tunnels are intended for development/demos; for production use a real domain
and a trusted certificate.
