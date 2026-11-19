# Incognito Messenger

A real-time messenger built with **Laravel 13**, **Laravel Reverb**, **Laravel Echo** and **MinIO**. It supports registration/login, profiles, friend requests, message requests, direct and group chats, read receipts, private file sharing, 24-hour notes, notifications, last-active presence, message sounds, unsend and a Messenger-style UI. Every HTTP request and WebSocket subscription is authorized on the backend.

## Features

- **Accounts & profiles** — registration, login/logout, throttling, editable name/email/username/bio/status and avatars.
- **Friend graph** — send/accept/reject/cancel friend requests with an optional note; only accepted friends appear in the Friends section and may start a normal direct chat. One pending request per pair.
- **Message requests** — non-friends can send a short introduction with one message. The thread is hidden from normal chat lists until the recipient accepts; accepting also creates the friendship. The recipient alone can delete an incoming request; the sender alone can cancel one.
- **Direct & group messages** — one conversation per pair, cursor-paginated history, latest-message previews, unread badges, typing whispers and presence.
- **Read receipts** — one grey tick for Sent, two blue ticks for Seen. A `message_reads` row is written only when a recipient actually opens the thread (never from a websocket receipt alone). Group messages show every reader, and receipts are included in every paginated message payload.
- **Last-active presence** — green dot for “Active now”, relative text for recent visits, nothing after seven days. A 30-second heartbeat and socket connect/disconnect events persist `users.last_active_at`; the **Show active status** profile toggle hides both presence and the timestamp.
- **Message sounds** — optional, on by default. A subtle tone plays for incoming messages (not for the focused chat, background-safe and deduplicated against your own socket echo) and a quieter one on successful send. Toggle it in **Profile → Message sounds**.
- **1-to-1 audio calls** — WebRTC audio-only calls between accepted friends: ringing/accept/decline/cancel, busy and offline handling, mute, live timer, connection quality, speaker selection, a floating call bar and call-history messages with **Call back** for missed calls. Signaling runs over private per-user Reverb channels; the server checks friendship, blocks, call policy and one-active-call-per-user before anything rings. STUN works out of the box; TURN is optional through environment variables. Settings include **Allow audio calls from**, a ringtone toggle, a microphone test and input/output device pickers.
- **Unsend** — sender-only. Unseen messages become a shared “unsent a message” placeholder on both sides; seen messages are removed only from the sender’s view. The server decides at request time from `message_reads`, and Reverb pushes the decision immediately.
- **Private media & files** — images (JPEG/PNG/GIF/WebP) and documents (PDF, Word, Excel, TXT, CSV, ZIP) up to 20 MB each, max 5 per message. Everything is stored in a private MinIO bucket with generated names. Download/preview links are authorized by policy and stream through the app, so they work from localhost, a LAN address or a tunnel without exposing MinIO to the browser.
- **Notes** — one active 60-character note per user, shown as a thought cloud in the notes strip and above avatars in Friends and Chats. Notes are visible only to the author and accepted friends, disappear after 24 hours, and can be edited or deleted anytime. Clicking a friend's note opens their chat.
- **Realtime** — new messages, read receipts, friend/message request events, accepts, notes, unread counts, conversation-list changes and group changes are pushed over Reverb. Request and notification counters update without a refresh.
- **Authorization** — policies plus `routes/channels.php` decide who may read, write, download or subscribe. Channel authorization is re-checked live for groups, so removed members lose access immediately.
- **Responsive UI** — Chats stay in a fixed-height, scrollable left sidebar; the right panel has Friends and Requests tabs, with online/offline presence, chat/remove actions, friend requests, sent requests and message requests. It opens from the people icon. On small screens the right panel and an open chat become full-screen drilldowns. Includes an emoji picker, attachment progress/previews, an image lightbox and a notification center.

## Stack

| Piece | Version / role |
|---|---|
| Laravel | 13.43 (PHP 8.4) |
| Reverb | WebSocket server on port 8080 |
| Echo + pusher-js | browser WebSocket client |
| Vite + Tailwind | frontend build |
| SQLite | app database in the `db-data` Docker volume |
| MinIO | private S3-compatible object storage on host ports 19000 (API) / 19001 (console) |

## Run it

```bash
cd ~/incognito-messenger
cp .env.example .env
php artisan key:generate
docker compose up -d --build
```

Open **http://localhost:8000**. The MinIO console is at **http://localhost:19001** (credentials from `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY`, both `minioadmin` by default). Host ports 19000/19001 are used because 9000/9001 are occupied by another local service on this machine; inside Docker, MinIO still listens on 9000/9001.

| Service | Job | Port |
|---|---|---|
| `minio` | Private object storage | 19000, 19001 |
| `minio-init` (one-shot) | Creates/protects the bucket | — |
| `migrate` (one-shot) | Migrates + seeds demo accounts | — |
| `assets` (one-shot) | Copies built JS/CSS into the nginx volume | — |
| `app` | PHP-FPM / Laravel | internal |
| `webserver` | nginx → PHP-FPM | 8000 |
| `reverb` | WebSocket server | 8080 |

The MinIO image is built locally from the Alpine `minio` and `minio-client` packages because the official `minio/minio` image is no longer publicly pullable. Bucket provisioning runs before the app starts.

Demo accounts (password `password` for all): `alice@example.com`, `bob@example.com`, `charlie@example.com`, `dana@example.com`. They are intentionally **not** friends on a fresh database — send a friend request or a message request from the UI to unlock direct chatting. You can also register your own account.

Stop with `docker compose down`. Data persists in `db-data` and `minio-data`; `docker compose down -v` wipes both.

After changing PHP code, rebuild and restart nginx so it picks up the new PHP-FPM address:

```bash
docker compose up -d --build
docker compose restart webserver
```

### Accessing from other machines

Ports are published on all interfaces. Open `http://<server-ip>:8000`. `resources/js/echo.js` derives the WebSocket host from `window.location.hostname`, so the same image connects to `ws://<server-ip>:8080` without a rebuild. Change `REVERB_APP_SECRET` and the MinIO credentials before exposing the app publicly. Allow ports 8000, 8080, 19000 and 19001 through the firewall as needed.

## Configuration

The example environment includes these MinIO settings:

```dotenv
FILESYSTEM_DISK=minio
MINIO_ENDPOINT=http://minio:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_BUCKET=incognito
MINIO_REGION=us-east-1
MINIO_USE_SSL=false
MINIO_URL=http://localhost:19000
```

`MINIO_ENDPOINT` is the server-to-server address used inside Docker (`http://minio:9000`); `MINIO_URL` is the optional browser-visible origin for the signed-URL disk. The browser never needs it: avatars and attachment previews are served through Laravel routes, so only the app's origin matters. Never put `MINIO_SECRET_KEY` in a `VITE_*` variable.

### Audio calls and TURN

Audio calls need a secure browser context: **HTTPS**, or `http://localhost` / `http://127.0.0.1` during development. Microphone permission is requested only when a call starts or is accepted.

STUN is enough when both people are on the same LAN. Calls across different networks need a TURN relay. Configure it with:

```dotenv
WEBRTC_STUN_URLS="stun:stun.l.google.com:19302"
WEBRTC_TURN_URLS="turn:turn.example.com:3478?transport=udp,turns:turn.example.com:5349?transport=tcp"
WEBRTC_TURN_USERNAME=calluser
WEBRTC_TURN_CREDENTIAL=strong-password
```

`WEBRTC_STUN_URLS` and `WEBRTC_TURN_URLS` accept comma-separated lists. Credentials are returned only by the authenticated `/api/calls/config` endpoint and are never committed to the JS bundle. Restart PHP after changing them.

Signaling generates many short, concurrent writes (presence, call state, SDP/ICE). On SQLite the shipped configuration uses WAL mode, a 10s busy timeout and `IMMEDIATE` transactions, and rate limiting uses the **file** cache (`CACHE_STORE=file`, set in `docker-compose.yml`) so throttle counters never lock the database table. If you move to MySQL/Postgres or Redis cache, remove those SQLite-specific settings as appropriate.

A minimal self-hosted coturn instance:

```bash
docker run -d --name coturn --restart=always --network=host \
  coturn/coturn -n --log-file=stdout --lt-cred-mech --fingerprint \
  --realm=turn.example.com --user=calluser:strong-password \
  --min-port=49160 --max-port=49200 --no-cli
```

Open `3478/udp`, `3478/tcp` and the relay range `49160-49200/udp` in the firewall. For `turns:` add a TLS certificate and expose `5349/tcp`. Hosted TURN providers (Twilio Network Traversal, Metered, Cloudflare Calls, etc.) work the same way: put their URLs and short-lived credentials in the same variables.

## API overview

All routes below require the session cookie and CSRF header.

| Area | Endpoints |
|---|---|
| Friends | `GET/POST /api/friends`, `POST /api/friends/{request}/accept`, `POST /api/friends/{request}/reject`, `DELETE /api/friends/{request}`, `DELETE /api/friends/user/{user}` |
| Message requests | `GET/POST /api/message-requests`, `GET /api/message-requests/{request}`, `POST /api/message-requests/{request}/accept`, `POST /api/message-requests/{request}/delete`, `DELETE /api/message-requests/{request}` |
| Notes | `GET/POST /api/notes`, `GET/PUT/DELETE /api/notes/{note}` |
| Notifications | `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all` |
| Attachments | `GET /api/attachments/{id}/download`, `GET /api/attachments/{id}/preview` |
| Chat | `GET /api/conversations`, `GET /api/conversations/{id}`, `POST /api/conversations/direct/{user}`, `POST /api/conversations/{id}/messages`, `POST /api/messages/{id}/unsend` |
| Presence | `GET /api/presence`, `POST /api/presence/heartbeat`, `POST /api/presence/disconnect` |
| Calls | `GET /api/calls/config`, `POST /api/calls/invite`, `POST /api/calls/{call}/ringing`, `POST /api/calls/{call}/leave`, `POST /api/calls/{call}/accept`, `POST /api/calls/{call}/reject`, `POST /api/calls/{call}/cancel`, `POST /api/calls/{call}/timeout`, `POST /api/calls/{call}/end`, `POST /api/calls/{call}/signal` |

### Message sound assets

The two bundled files are tiny 8-bit-style PCM WAV cues:

- `public/sounds/received.wav` — incoming message ping
- `public/sounds/sent.wav` — quieter confirmation on successful send

Replace either file with your own short WAV (or MP3/OGG and update the paths in `resources/js/app.js`) without changing any PHP code. They are served straight from `public/`, so a `npm run build` is not required for a replacement to take effect; clients may need a hard refresh to bypass the browser cache. Audio is unlocked on the first click/keypress and failures are ignored silently.

## How authorization works

```
Browser ──HTTP──► Controllers ──Gate/policy──► Database / MinIO
   │                    │
   │ WebSocket          └─ broadcast events ──► Reverb ──► allowed subscribers
   └── /broadcasting/auth ──► routes/channels.php (live membership check)
```

- `ConversationPolicy`, `GroupPolicy`, `FriendRequestPolicy`, `MessageRequestPolicy`, `NotePolicy`, `NotificationPolicy` and `MessageAttachmentPolicy` gate every endpoint.
- `routes/channels.php` checks participation/membership live for `conversation.{id}`; `user.{id}` is restricted to that user. Echo adds the wire prefix, so callbacks use bare names.
- Attachments are authorized **before** a temporary MinIO URL is generated. The URL cannot bypass the policy because it is short-lived and issued only to a participant.
- The sender identity always comes from `auth()->id()` — request payloads cannot impersonate another user.
- Message history uses cursor pagination (30 per page). Every message payload carries its own `status`, `seen_by` and `attachments`, so pagination and live events share one render path.

## Project layout

| Path | Purpose |
|---|---|
| `app/Models/` | Users, profiles, conversations, groups, messages, reads, friends, requests, notes, notifications, attachments, calls |
| `app/Policies/` | Backend authorization rules for every owned resource |
| `app/Http/Controllers/` | Auth, profile, avatar, chat, group, friend, request, note, notification, attachment and call controllers |
| `app/Events/` | `MessageSent`, `MessageSeen`, `MessageUnsent`, `PresenceUpdated`, `NotificationCreated`, `ConversationsUpdated`, `UnreadCountsUpdated`, `AccessRevoked`, `GroupUpdated`, `CallEvent` |
| `app/Support/` | `UnreadCounter`, `PairKey`, `Notifier`, `AttachmentUpload`, `ReverbConnectionTerminator`, `CallService` |
| `app/Console/Commands/` | `PruneOrphanedFiles`, `ExpireCalls` (scheduled every minute as a ringing-call safety net) |
| `database/migrations/` | Full schema, indexes, foreign keys and cascades |
| `docker/minio/` | Locally built MinIO server image and bucket init script |
| `resources/js/app.js` | UI behaviour, fetch/XHR calls and Echo subscriptions |
| `resources/js/calls/` | WebRTC engine, Alpine call store, overlay and chat call button |
| `resources/css/calls.css` | Call overlay, floating bar and call-history styles |
| `resources/css/app.css` | Messenger layout, notes, receipts, presence, unsend menu and attachment styles |
| `public/sounds/` | Small preloaded WAV files used by the notification sounds |

## Development

```bash
# PHP tests (in-memory SQLite + Storage::fake('minio'); no Docker required)
php artisan test

# Frontend production build
npm run build

# Inside Docker
docker compose exec app php artisan test
docker compose exec app php artisan migrate:status
docker compose exec app php artisan storage:prune-orphaned-files --dry-run
docker compose logs -f reverb
docker compose logs -f minio
```

`storage:prune-orphaned-files` only scans application-owned prefixes (`avatars/` and `conversations/`) and removes objects that no profile, group or message attachment references. Run with `--dry-run` first. Failed uploads are cleaned up immediately in the request; the command catches other orphans (for example, from an interrupted deploy).

Tests cover registration/login, profile and avatar validation, direct-chat privacy, friend requests, message requests, rejection/cancellation, notes/expiry/visibility, read receipts (including groups and pagination), MinIO attachment authorization, realtime event/channel rules, notifications, group management, audio-call signaling/state/history, cascades, storage pruning and schema indexes.

## Notes

- Events use `ShouldBroadcastNow` so delivery is immediate without a queue worker. For high traffic, switch to `ShouldBroadcast` and run `php artisan queue:work`.
- Typing indicators are whispers: browser → Reverb → other browsers. They never reach Laravel and are throttled in the client.
- The WebSocket key is baked into the JS bundle at build time; changing `REVERB_APP_KEY` requires `docker compose build`.
- Avatar uploads are capped at 10 MB and message attachments at 20 MB each (5 per message). PHP allows 25 MB per file and 110 MB per request, and nginx allows 110 MB request bodies, so Laravel validates every file before PHP or nginx can truncate a valid batch.
