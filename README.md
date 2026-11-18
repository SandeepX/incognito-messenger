# Incognito Messenger

A production-shaped, real-time messenger built with **Laravel 13**, **Laravel Reverb** and **Laravel Echo**. It supports registration and login, editable profiles with avatars, one-to-one chats, groups, live delivery, unread badges, typing indicators and online presence — with authorization enforced on the backend for every request and every WebSocket subscription.

## Features

- **Accounts** — registration, login/logout, hashed passwords, validation, session rotation, login throttling and protected routes.
- **Profiles** — full name, unique username, email, bio/status and avatar upload (MIME + size validated, served through Laravel).
- **Direct messages** — search users, one conversation per pair, paginated history, latest-message preview, unread counts and read receipts. Online/offline status via a presence channel.
- **Groups** — create named groups with an optional avatar, search/select members, creator is admin, admins add/remove members, members leave, admins delete.
- **Real time** — messages, unread-count updates, group updates and membership revocation are pushed over Reverb; typing uses client-side whispers. The UI shows a connection banner if the socket drops and resyncs when it reconnects.
- **Security** — policies plus `routes/channels.php` decide who may read, write or subscribe. Removing a member detaches their conversation participation immediately, so future HTTP requests and channel auth attempts fail; existing sockets are also closed through Reverb's terminate-connections API. Avatars stay in private storage.
- **Responsive UI** — Messenger-style two-pane layout on desktop/tablet and a list → chat drill-down on mobile. Enter sends, Shift+Enter inserts a newline.

## Stack

| Piece | Version / role |
|---|---|
| Laravel | 13.43 (PHP 8.4) |
| Reverb | 1.14 (WebSocket server on port 8080) |
| Echo + pusher-js | 2.6 / 8.6 (browser client) |
| Vite + Tailwind | 8 / 4 (frontend build) |
| SQLite | database in the `db-data` Docker volume |

## Run it

```bash
cd ~/incognito-messenger
cp .env.example .env
# Generate an app key (any one of these works):
php artisan key:generate
# or: sed -i "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32)|" .env

docker compose up -d --build
```

Open **http://localhost:8000**. The example environment already points the
browser at `ws://<page-host>:8080`; change `REVERB_APP_SECRET` before exposing
the app publicly.

| Service | Job | Port |
|---|---|---|
| `migrate` (one-shot) | Migrates + seeds demo accounts | — |
| `assets` (one-shot) | Copies built JS/CSS into the nginx volume | — |
| `app` | PHP-FPM / Laravel | internal |
| `webserver` | nginx → PHP-FPM | 8000 |
| `reverb` | WebSocket server | 8080 |

Demo accounts (password `password` for all): `alice@example.com`, `bob@example.com`, `charlie@example.com`, `dana@example.com`. You can also register your own account.

Stop with `docker compose down`. Data persists in the `db-data` volume; `docker compose down -v` wipes it.

After changing PHP code, rebuild and restart nginx so it picks up the new PHP-FPM address:

```bash
docker compose up -d --build
docker compose restart webserver
```

### Accessing from other machines

Ports are published on all interfaces. Open `http://<server-ip>:8000`. `resources/js/echo.js` derives the WebSocket host from `window.location.hostname`, so the same image connects to `ws://<server-ip>:8080` without a rebuild. Make sure the firewall allows ports 8000 and 8080.

## How authorization works

```
Browser ──HTTP──► Controllers ──Gate/policy──► Database
   │                    │
   │ WebSocket          └─ broadcast events ──► Reverb ──► allowed subscribers
   └── /broadcasting/auth ──► routes/channels.php (live membership check)
```

- `ConversationPolicy` / `GroupPolicy` gate every history, send, group-info and management endpoint.
- `routes/channels.php` checks participation/membership **live** for `conversation.{id}`; `user.{id}` is restricted to that user. Echo adds the `private-`/`presence-` prefix on the wire, Laravel strips it, so the callbacks use bare names (`online`, `conversation.{id}`, `user.{id}`).
- `AccessRevoked` makes an open browser leave a conversation the moment it is removed. `ReverbConnectionTerminator` additionally closes that user's live sockets, so an old subscription cannot keep receiving messages; the backend check still blocks every future HTTP request and subscription.
- The sender identity always comes from `auth()->id()` — request payloads cannot impersonate another user.
- Message history uses cursor pagination (30 per page) and unread counts are computed in one grouped query.

## Project layout

| Path | Purpose |
|---|---|
| `app/Models/` | `User`, `UserProfile`, `Conversation`, `ConversationParticipant`, `Group`, `GroupMember`, `Message`, `MessageRead` |
| `app/Policies/` | Conversation and group authorization rules |
| `app/Http/Controllers/` | Auth, profile, avatar, conversation, message, group and user-search controllers |
| `app/Events/` | `MessageSent`, `UnreadCountsUpdated`, `AccessRevoked`, `GroupUpdated` |
| `app/Support/UnreadCounter.php` | Fast per-conversation unread aggregation |
| `app/Support/ReverbConnectionTerminator.php` | Closes live sockets when group access is revoked |
| `database/migrations/` | Full schema, indexes, foreign keys and cascades |
| `routes/web.php` | All HTTP routes (public auth + authenticated messenger/API) |
| `routes/channels.php` | Private/presence channel authorization |
| `resources/views/` | Auth, profile and Messenger Blade templates |
| `resources/js/app.js` | UI behaviour, fetch calls and Echo subscriptions |
| `resources/js/echo.js` | Reverb connection setup (LAN-safe host) |
| `resources/css/app.css` | Messenger layout and responsive styles |

## Development

```bash
# PHP tests (uses in-memory SQLite; no Docker required)
php artisan test

# Frontend production build
npm run build

# Inside Docker
docker compose exec app php artisan test
docker compose exec app php artisan migrate:status
docker compose logs -f reverb
```

Feature tests cover registration/login, profile updates and avatar validation, direct-chat privacy, unread counts, group creation/management, immediate access revocation (HTTP **and** channel callbacks), deletion cascades and schema indexes.

## Notes

- Events use `ShouldBroadcastNow` so delivery is immediate without a queue worker. For high traffic, switch to `ShouldBroadcast` and run `php artisan queue:work`.
- Typing indicators are whispers: browser → Reverb → other browsers. They never reach Laravel and are throttled in the client.
- The WebSocket key is baked into the JS bundle at build time; changing `REVERB_APP_KEY` requires `docker compose build`.
