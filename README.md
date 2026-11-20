# Incognito Messenger

**A self-hosted, real-time messenger for friends, groups and private conversations — built with Laravel 13, Laravel Reverb, Laravel Echo and MinIO.**

Incognito Messenger is a full-stack chat application with accounts and profiles, a friend graph, message requests, direct and group conversations, read receipts, presence, 24-hour notes, private file sharing, browser notifications and 1-to-1 WebRTC audio calls. Every HTTP request and every WebSocket subscription is authorized on the server. The whole stack — app, WebSocket server, database and object storage — runs locally with Docker Compose.

> **Privacy note:** the name is a product name, not a claim of end-to-end encryption. Messages and files are stored on the server you run (SQLite + private MinIO), and the server can read message bodies. It is private by design — no third-party chat or storage service is involved — but it is not a replacement for a hardened E2E-encrypted messenger.

---

## Table of contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Quick start with Docker](#quick-start-with-docker)
- [Local development](#local-development)
- [Demo accounts](#demo-accounts)
- [Configuration](#configuration)
- [Using the app](#using-the-app)
- [API overview](#api-overview)
- [Authorization and security model](#authorization-and-security-model)
- [Project structure](#project-structure)
- [Realtime events](#realtime-events)
- [Development and testing](#development-and-testing)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [License](#license)

---

## Features

### Accounts, profiles and security

- **Registration and login** with server-side validation, a remember-me option, session regeneration and throttled login attempts (6 per minute).
- **Email verification** links and resend support. Changing your email clears the verified state. Verification is currently optional rather than enforced at login.
- **Profiles** with full name, unique username (live availability check), email, 60-character status message, 160-character bio and a JPEG/PNG/GIF/WebP avatar up to 10 MB with an in-browser crop step.
- **Password management** with current-password confirmation, confirmation field and a live strength indicator.
- **Active sessions** — see signed-in devices, revoke individual sessions, or log out of every other device after confirming your password.
- **Account deletion** with password confirmation and typing `DELETE`; the account, sessions and related data are removed.
- **Appearance** — system, light or dark theme, applied instantly and saved to the account.
- **Privacy controls** — show/hide active status, enable/disable read receipts, choose who may send message requests (`Everyone`, `Friends of friends`, `Nobody`), choose who may see notes (`Friends` or `Nobody`) and who may call you (`Friends` or `Nobody`).
- **Blocking** — block and unblock users from Settings or from a message request. Blocked users cannot search for you, message you or call you, and neither side sees the other's presence.
- **Notification preferences** — message sounds on/off, volume slider, test sound, desktop browser notifications, call ringtone and audio device selection.

### Friends, discovery and message requests

- **User discovery** by display name or username. The friend finder excludes yourself, blocked users, existing friends, pending requests in either direction and anyone you already share a conversation with.
- **Friend requests** with an optional 160-character note. Exactly one pending request per pair is allowed; accept, reject or cancel at any time, with undo actions for recent decisions.
- **Message requests** — the only way a non-friend can start a conversation. The sender writes a short introduction (up to 2,000 characters) and one message. The thread stays out of both normal chat lists until the recipient accepts. Accepting creates the friendship and unlocks normal chat.
- **Request controls** — the recipient can delete, block or report an incoming request; the sender can cancel it. Undo actions restore the previous state. Reporting currently removes the request; there is no separate moderation queue.
- **Badges and notifications** — unseen friend and message requests are counted and pushed live, with separate incoming and sent request views.

### Direct and group conversations

- **One direct conversation per pair**, created with a deterministic pair key so duplicate threads cannot occur. Direct chats are between accepted friends only.
- **Group chats** with a name, 300-character description, optional avatar and one or more other registered members. The creator becomes admin and cannot be removed.
- **Group administration** — admins can rename the group, update the description/avatar, add members, remove members and delete the group. Members can leave on their own; the creator must delete the group instead of leaving it.
- **Live access revocation** — removing a member or deleting a group broadcasts a revocation event and closes the affected Reverb sockets. The next channel authorization request also fails, so access cannot be retained by an already-open tab.
- **Message history** with cursor pagination (30 messages per page), date separators, latest-message previews, unread counts and older-message loading.
- **Read receipts** — one grey tick for sent, two blue ticks for seen. Receipts are written only when a recipient actually opens the conversation. Group messages list every reader. Disabling read receipts hides yours and others' receipts.
- **Unsend / delete** — the sender may delete an unseen message for everyone; it becomes a shared "unsent a message" placeholder. Anyone may delete any message for themselves only, which hides it from their own history. Seen messages cannot be deleted for everyone, and the server makes that decision from `message_reads` at request time.
- **Typing indicators** over Reverb whispers, throttled in the client and never persisted by Laravel.
- **Conversation mute** per browser/device, plus an in-chat search that filters the currently loaded messages.
- **Emoji picker**, message grouping, timestamps, a floating notification center and toast feedback.

### Private media and attachments

- **Images** (JPEG, PNG, GIF, WebP) and **documents** (PDF, Word, Excel, TXT, CSV, ZIP).
- Up to **5 attachments per message**, **20 MB each**. Avatars are capped at 10 MB.
- Files are stored in a **private MinIO bucket** under generated names. The bucket is never exposed to the browser.
- Downloads and image previews are authorized by policy and **streamed through Laravel**, so they work from localhost, a LAN IP, a tunnel or a domain without exposing MinIO.
- Upload progress bars, attachment previews, an image lightbox and safe filename handling.
- Failed uploads are cleaned up immediately; `storage:prune-orphaned-files` catches leftovers.

### Notes

- One active note per user, up to **60 characters**, shown as a thought cloud in the notes strip and above avatars.
- Visible only to the author and accepted friends (or hidden entirely with the `Nobody` visibility setting).
- Notes are hidden after **24 hours** and can be edited or deleted at any time.
- Clicking a friend's note opens that friend's chat.

### Presence

- **Online now** and relative last-active text (`Active 5 min ago`, `Active 2 hrs ago`, `Active 3 days ago`), hidden after seven days.
- A 30-second heartbeat plus Reverb connect/disconnect events persist `users.last_active_at`.
- The **Show active status** toggle hides both the online dot and the timestamp. Blocking also hides presence from the other person.
- Presence is broadcast only to people who share a conversation or friendship with the user.

### 1-to-1 audio calls

- **WebRTC audio-only calls** between accepted friends, with ringing, accept, decline, busy, cancel, timeout and end states.
- The server checks friendship, blocks, the recipient's call policy and one-active-call-per-user before anything rings.
- **Call UI** — mute, live timer, connection-quality indicator, speaker selection, a floating call bar, and call-history messages in chat.
- **Call back** action for missed calls.
- **Settings** — who may call you, ringtone toggle, microphone test with a level meter, and input/output device pickers.
- **Signaling** travels over private per-user Reverb channels and the call API, never through public channels.
- **STUN works out of the box**; TURN is optional through environment variables for calls across different networks.
- Call-history messages cannot be deleted.
- Calls require a secure browser context: HTTPS, `http://localhost` or `http://127.0.0.1`.

### Realtime and UI

- Live messages, read receipts, typing, presence, unread counts, conversation-list updates, friend/message request events, notes and group changes over Reverb.
- No page refresh needed for counters, previews, badges or notifications.
- **Messenger-style layout** — fixed-height scrollable chat sidebar, notes strip, Friends and Requests panel, conversation info, group settings and a notification center.
- **Responsive** — the right panel and an open chat become full-screen drilldowns on small screens.
- **Accessible controls** with labels, roles and keyboard-friendly modals.
- **Debug diagnostics** — with `APP_DEBUG=true`, a connection panel shows secure-context status, microphone permission, Echo/Reverb state, ICE state and candidate type.

---

## Tech stack

| Piece | Version / role |
|---|---|
| PHP | 8.4 (application requires PHP 8.3+) |
| Laravel | 13.43 |
| Laravel Reverb | 1.14 — WebSocket server on port 8080 |
| Laravel Echo + pusher-js | browser WebSocket client |
| Alpine.js | call UI state and overlay |
| Vite + Tailwind CSS | frontend build and styling |
| SQLite | app database, stored in the `db-data` Docker volume |
| MinIO | private S3-compatible object storage, host ports 19000 (API) / 19001 (console) |
| Pest + PHPUnit | automated test suite |
| Laravel Pint | code style |
| Docker Compose | app, nginx, PHP-FPM, Reverb, MinIO and one-shot init jobs |

---

## Architecture

```text
Browser
  │
  ├── HTTP ──────────────► nginx :8000 ──► PHP-FPM (Laravel)
  │                                          │
  │                                          ├── SQLite (db-data volume)
  │                                          └── MinIO (private bucket)
  │
  └── WebSocket ─────────► Reverb :8080
                             ▲
                             │ broadcast events
                             │
                    /broadcasting/auth ──► routes/channels.php
                                            (live membership checks)
```

The browser connects to the WebSocket through the **same origin** that served the page. In the Docker stack, nginx proxies `/app` and `/apps` to Reverb. In the Caddy setup, Caddy does the same. Echo derives the host, port and TLS from `window.location`, so one production bundle works on localhost, a LAN IP, a tunnel or a real domain.

---

## Quick start with Docker

### Prerequisites

- Docker Engine with Docker Compose v2
- PHP 8.3+ and Composer 2 on the host (for `composer install` and key generation before the first build)
- Git

### Run

```bash
git clone https://github.com/SandeepX/incognito-messenger.git
cd incognito-messenger

composer install
cp .env.example .env
php artisan key:generate

docker compose up -d --build
```

Open **http://localhost:8000**.

- **MinIO console:** http://localhost:19001 — credentials come from `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` (both `minioadmin` by default).
- Host ports **19000/19001** are used because 9000/9001 are often occupied. Inside the Docker network, MinIO still listens on 9000/9001.

If you do not have PHP installed locally, build the image first and generate the key inside the container:

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
```

### Services

| Service | Job | Port |
|---|---|---|
| `minio` | Private S3-compatible object storage | 19000, 19001 |
| `minio-init` (one-shot) | Creates and protects the bucket | — |
| `migrate` (one-shot) | Migrates the schema and seeds demo accounts | — |
| `assets` (one-shot) | Copies built JS/CSS into the nginx volume | — |
| `app` | PHP-FPM / Laravel | internal |
| `webserver` | nginx → PHP-FPM | 8000 |
| `reverb` | Laravel Reverb WebSocket server | 8080 (also proxied via 8000) |

The MinIO image is built locally from the Alpine `minio` and `minio-client` packages because the official `minio/minio` image is no longer publicly pullable. Bucket provisioning and migrations run before the app and Reverb start.

### Stop and reset

```bash
docker compose down          # stop, keep data
docker compose down -v       # stop and wipe db-data, minio-data and assets
```

After changing PHP code:

```bash
docker compose up -d --build
docker compose restart webserver
```

After changing only frontend assets, rebuild the image (the `assets` job copies the new bundle into nginx):

```bash
docker compose up -d --build
```

### Access from other devices

Docker publishes the ports on all interfaces, so other machines on the LAN can open `http://<server-ip>:8000`. The browser's WebSocket connection uses that same origin, and nginx proxies it to Reverb — you do not need to open port 8080 for normal use.

For firewall rules, expose **8000** to the devices that use the app. Keep **19000/19001** (MinIO) private unless you specifically need the console, and avoid exposing **8080** directly when nginx already proxies it. Audio calls require HTTPS on anything other than localhost; use the [LAN HTTPS guide](docs/lan-https-setup.md).

---

## Local development

You can run Laravel, Vite and Reverb on the host instead of Docker. MinIO is still required for avatars and attachments.

### Prerequisites

- PHP 8.3+ with `pdo_sqlite`, `mbstring` and `pcntl` (needed by Reverb)
- Composer 2
- Node.js 22+ and npm
- MinIO

### Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate --seed

npm install
npm run build
```

Start MinIO. The easiest option is to reuse the Docker storage services:

```bash
docker compose up -d minio minio-init
```

Then point the local app at the host-published MinIO port in `.env`:

```dotenv
MINIO_ENDPOINT=http://127.0.0.1:19000
MINIO_URL=http://127.0.0.1:19000
```

Run everything with the Laravel dev command:

```bash
composer dev
```

This starts `php artisan serve`, Reverb, the queue listener, Pail and Vite together. If you prefer separate terminals:

```bash
php artisan serve                 # http://localhost:8000
php artisan reverb:start          # ws://localhost:8080
npm run dev                       # Vite
```

For the split-port local setup (app on 8000, Reverb on 8080, no proxy), tell the browser which port to use:

```dotenv
REVERB_BROWSER_PORT=8080
```

For the single-origin Caddy setup, leave `REVERB_BROWSER_PORT` empty. See [LAN HTTPS setup](docs/lan-https-setup.md).

---

## Demo accounts

Migrations seed four accounts. The password for all of them is `password`:

| Email | Username |
|---|---|
| `alice@example.com` | `alice` |
| `bob@example.com` | `bob` |
| `charlie@example.com` | `charlie` |
| `dana@example.com` | `dana` |

They are intentionally **not friends** on a fresh database. Send a friend request or a message request from the UI to unlock direct chatting, or register your own account.

---

## Configuration

All settings live in `.env`. Copy `.env.example` as a starting point and change secrets before exposing the app to a network.

### Application and database

```dotenv
APP_NAME="Incognito Messenger"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=file
QUEUE_CONNECTION=database
```

The Docker stack overrides `DB_DATABASE` to `/var/www/storage/db/database.sqlite` so the database lives in the `db-data` volume. Local SQLite defaults to `database/database.sqlite`.

### Reverb and WebSockets

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=1
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Laravel → Reverb publishing (server-to-server)
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Browser override. Empty = same host/port as the page.
REVERB_BROWSER_PORT=
REVERB_BROWSER_PATH=

# Reverb compares only the hostname from the Origin header.
REVERB_ALLOWED_ORIGINS=*
```

- The **key** is public and embedded in the browser bundle. Changing `REVERB_APP_KEY` requires rebuilding the Docker image.
- The **secret** must stay server-side and never appear in a `VITE_*` variable.
- Inside Docker, `REVERB_HOST` must be the service name `reverb`, not `localhost`. The Compose file sets this automatically.
- `REVERB_BROWSER_PORT` is only needed for a split-port setup with no reverse proxy. Leave it empty when nginx or Caddy proxies `/app` on the page's origin.
- `REVERB_ALLOWED_ORIGINS` accepts a comma-separated list of bare hostnames, for example `192.168.1.10` or `chat.example.com`. `*` is fine for local development.

### MinIO

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

- `MINIO_ENDPOINT` is the server-to-server address. Inside Docker it is `http://minio:9000`; from the host it is usually `http://127.0.0.1:19000`.
- `MINIO_URL` is the optional browser-visible origin for the signed-URL disk. The browser never needs it for avatars or attachments because those stream through Laravel.
- Never expose `MINIO_SECRET_KEY` to the browser.

### WebRTC audio calls and TURN

```dotenv
WEBRTC_STUN_URLS="stun:stun.l.google.com:19302"
# WEBRTC_TURN_URLS="turn:turn.example.com:3478?transport=udp,turns:turn.example.com:5349?transport=tcp"
# WEBRTC_TURN_USERNAME=
# WEBRTC_TURN_CREDENTIAL=
```

`WEBRTC_STUN_URLS` and `WEBRTC_TURN_URLS` accept comma-separated lists. STUN is enough on the same LAN; calls across different networks need a TURN relay. TURN credentials are returned only by the authenticated `/api/calls/config` endpoint and are never compiled into the JS bundle. Restart PHP after changing them.

A minimal self-hosted coturn instance:

```bash
docker run -d --name coturn --restart=always --network=host \
  coturn/coturn -n --log-file=stdout --lt-cred-mech --fingerprint \
  --realm=turn.example.com --user=calluser:strong-password \
  --min-port=49160 --max-port=49200 --no-cli
```

Open `3478/udp`, `3478/tcp` and the relay range `49160-49200/udp` in the firewall. For `turns:` add a TLS certificate and expose `5349/tcp`. Hosted TURN providers work the same way — put their URLs and credentials in the same variables.

### HTTPS and trusted proxies

Audio calls need a secure context. For LAN use, see [docs/lan-https-setup.md](docs/lan-https-setup.md), which covers Caddy, mkcert certificates and trusting the root CA on phones and other computers. The relevant environment variables are:

```dotenv
APP_URL=https://192.168.1.10:8443
TRUSTED_PROXIES=127.0.0.1
TRUSTED_HOSTS=192.168.1.10
CORS_ALLOWED_ORIGINS=https://192.168.1.10:8443
SESSION_SECURE_COOKIE=true
REVERB_ALLOWED_ORIGINS=192.168.1.10
```

### SQLite performance tuning

Call signaling produces many short, concurrent writes. On SQLite the shipped configuration uses WAL mode, a 10-second busy timeout and `IMMEDIATE` transactions. Rate limiting uses the **file** cache (`CACHE_STORE=file`) so throttle counters do not lock the database:

```dotenv
DB_BUSY_TIMEOUT=10000
DB_JOURNAL_MODE=wal
DB_TRANSACTION_MODE=IMMEDIATE
CACHE_STORE=file
```

If you move to MySQL/PostgreSQL and Redis, remove the SQLite-specific settings and use Redis for cache/sessions at scale.

---

## Using the app

1. **Create an account** at `/register`, or log in with a demo account.
2. **Find people** with the people icon → *Find friends*. Search by name or username and send a friend request with an optional note.
3. **Send a message request** to a non-friend. They see it under Requests; accepting also creates the friendship.
4. **Start chatting** from the Friends list. Messages arrive live; use the emoji picker, attachments or Enter to send.
5. **Create a group** with the `+` button → *Create a group*. Choose a name, description, avatar and members.
6. **Add a note** from the notes strip. It expires after 24 hours and is visible to accepted friends.
7. **Share files** with the paperclip. Images open in a lightbox; documents download through an authorized Laravel route.
8. **Place an audio call** from a friend's chat. Accept on the other side, then use mute, speaker selection and the call timer.
9. **Tune your account** in Settings: profile, appearance, notifications, calls, privacy, password, sessions and account deletion.
10. **Mute noisy chats**, search the loaded history with the in-chat search, and open the notification center from the bell.

---

## API overview

All endpoints below require the session cookie and a CSRF token, except the public auth pages and `/avatars/{filename}`. JSON requests should send `Accept: application/json`, `X-CSRF-TOKEN` and, for file uploads, `multipart/form-data`.

### Authentication and account

| Area | Endpoints |
|---|---|
| Registration | `GET /register`, `POST /register` |
| Login / logout | `GET /login`, `POST /login`, `POST /logout` |
| Email verification | `POST /email/verification-notification`, `GET /email/verify/{id}/{hash}` |
| Profile pages | `GET /profile`, `PUT /profile`, `POST /profile/avatar`, `DELETE /profile/avatar`, `PUT /profile/password`, `DELETE /profile` |
| Settings API | `GET /api/settings/username/check`, `POST /api/settings/appearance`, `POST /api/settings/notifications`, `PUT\|POST /api/settings/profile` |
| Blocked users | `GET /api/settings/blocked-users`, `POST /api/settings/blocked-users/{user}`, `DELETE /api/settings/blocked-users/{user}` |
| Sessions | `GET /api/settings/sessions`, `DELETE /api/settings/sessions/others`, `DELETE /api/settings/sessions/{session}` |

### People and requests

| Area | Endpoints |
|---|---|
| User search | `GET /api/users?q=&scope=find-friends` |
| Friends | `GET /api/friends`, `POST /api/friends`, `POST /api/friends/{friend_request}/accept`, `POST /api/friends/{friend_request}/reject`, `POST /api/friends/{friend_request}/undo-accept`, `POST /api/friends/{friend_request}/undo-reject`, `POST /api/friends/{friend_request}/undo-cancel`, `DELETE /api/friends/{friend_request}`, `DELETE /api/friends/user/{user}` |
| Message requests | `GET /api/message-requests`, `POST /api/message-requests`, `GET /api/message-requests/{message_request}`, `POST /api/message-requests/{message_request}/accept`, `POST /api/message-requests/{message_request}/delete`, `POST /api/message-requests/{message_request}/block`, `POST /api/message-requests/{message_request}/report`, `POST /api/message-requests/{message_request}/undo-accept`, `POST /api/message-requests/{message_request}/undo-delete`, `POST /api/message-requests/{message_request}/undo-cancel`, `DELETE /api/message-requests/{message_request}` |
| Request badges | `POST /api/requests/seen` |

### Chat, groups and media

| Area | Endpoints |
|---|---|
| Conversations | `GET /api/conversations`, `GET /api/conversations/{conversation}`, `POST /api/conversations/direct/{user}`, `POST /api/conversations/{conversation}/seen` |
| Messages | `POST /api/conversations/{conversation}/messages`, `POST /api/messages/{message}/seen`, `POST /api/messages/{message}/unsend` |
| Groups | `POST /api/groups`, `GET /api/groups/{group}`, `PUT /api/groups/{group}`, `POST /api/groups/{group}/members`, `DELETE /api/groups/{group}/members/{user}`, `POST /api/groups/{group}/leave`, `DELETE /api/groups/{group}` |
| Attachments | `GET /api/attachments/{attachment}/download`, `GET /api/attachments/{attachment}/preview` |
| Notes | `GET /api/notes`, `POST /api/notes`, `GET /api/notes/{note}`, `PUT /api/notes/{note}`, `DELETE /api/notes/{note}` |
| Notifications | `GET /api/notifications`, `POST /api/notifications/{notification}/read`, `POST /api/notifications/read-all` |

### Presence and calls

| Area | Endpoints |
|---|---|
| Presence | `GET /api/presence`, `POST /api/presence/heartbeat`, `POST /api/presence/disconnect` |
| Calls | `GET /api/calls/config`, `POST /api/calls/invite`, `POST /api/calls/{call}/ringing`, `POST /api/calls/{call}/leave`, `POST /api/calls/{call}/accept`, `POST /api/calls/{call}/reject`, `POST /api/calls/{call}/busy`, `POST /api/calls/{call}/cancel`, `POST /api/calls/{call}/timeout`, `POST /api/calls/{call}/end`, `POST /api/calls/{call}/signal` |

### Request bodies worth knowing

- **Unsend:** `POST /api/messages/{message}/unsend` with `mode=everyone` or `mode=me`.
- **Direct chat:** `POST /api/conversations/direct/{user}` creates or returns the one thread for that pair.
- **Group create:** `POST /api/groups` with `name`, optional `description`, optional `avatar` and `member_ids[]`.
- **Message send:** `POST /api/conversations/{conversation}/messages` with `body` (max 2,000) and/or `attachments[]` (max 5 files, 20 MB each).
- **Message request:** `POST /api/message-requests` with `user_id` and `body` (max 2,000).
- **Note:** `POST /api/notes` with `content` (max 60); notes expire 24 hours after creation.

### Rate limits

| Action | Limit |
|---|---|
| Login | 6 / minute |
| Email verification resend | 6 / minute |
| Username availability check | 30 / minute |
| Message send | 120 / minute |
| Mark seen | 240 / minute |
| Unsend | 60 / minute |
| Presence heartbeat | 120 / minute |
| Call invite | 6 / minute |
| Call accept/reject/busy/cancel/timeout | 30 / minute |
| Call ringing/leave/end | 60 / minute |
| Call signaling | Not throttled; bounded by validation and active-participant checks |

---

## Authorization and security model

```text
Browser ──HTTP──► Controllers ──Gate/policy──► Database / MinIO
   │                    │
   │ WebSocket          └─ broadcast events ──► Reverb ──► allowed subscribers
   └── /broadcasting/auth ──► routes/channels.php (live membership check)
```

- **Policies** gate every owned resource: `ConversationPolicy`, `GroupPolicy`, `FriendRequestPolicy`, `MessageRequestPolicy`, `NotePolicy`, `NotificationPolicy` and `MessageAttachmentPolicy`.
- **Channel authorization** in `routes/channels.php` is re-checked on every subscription:
  - `conversation.{id}` checks direct participation or live group membership.
  - `user.{id}` and `users.{id}` are restricted to that user's own id.
  - `call.{callId}` is restricted to the two call participants.
- **Removing a member** deletes the conversation participant row, broadcasts `AccessRevoked`, and terminates the user's Reverb connections, so an open tab cannot keep receiving messages.
- **Attachments** are authorized before any bytes are streamed. The private MinIO object is never exposed by a public URL.
- **Identity** always comes from `auth()->id()`; request payloads cannot impersonate another user.
- **Message payloads** carry their own `status`, `seen_by` and `attachments`, so paginated history and live events render through the same path.
- **Rate limiting** protects login, account discovery, messaging, presence, verification and call setup.

### Before exposing it publicly

1. Change `REVERB_APP_SECRET`, `MINIO_ACCESS_KEY` and `MINIO_SECRET_KEY`.
2. Set `APP_ENV=production` and `APP_DEBUG=false`.
3. Use HTTPS and set `SESSION_SECURE_COOKIE=true`, `APP_URL` and the origin/host allowlists.
4. Set `REVERB_ALLOWED_ORIGINS` to the exact hostname(s) that serve the app.
5. Back up the `db-data` and `minio-data` volumes (or your external database and bucket).
6. Put the stack behind a real reverse proxy or tunnel with a trusted certificate.
7. Keep `MINIO_SECRET_KEY` server-side and never add secrets to `VITE_*` variables.

---

## Project structure

| Path | Purpose |
|---|---|
| `app/Models/` | Users, profiles, conversations, groups, messages, reads, friends, requests, notes, notifications, attachments, blocks and calls |
| `app/Policies/` | Backend authorization rules for every owned resource |
| `app/Http/Controllers/` | Auth, settings, chat, group, friend, request, note, notification, attachment, presence and call controllers |
| `app/Http/Requests/` | Form-request validation for auth, profile, password, avatar and account deletion |
| `app/Events/` | `MessageSent`, `MessageSeen`, `MessageUnsent`, `PresenceUpdated`, `NotificationCreated`, `NotificationRead`, `ConversationsUpdated`, `UnreadCountsUpdated`, `AccessRevoked`, `GroupUpdated`, `CallEvent` |
| `app/Support/` | `UnreadCounter`, `PairKey`, `Notifier`, `AttachmentUpload`, `AvatarUpload`, `UnsendPolicy`, `ReverbConnectionTerminator`, `CallService` |
| `app/Console/Commands/` | `PruneOrphanedFiles`, `ExpireCalls` |
| `database/migrations/` | Full schema, indexes, foreign keys and cascades |
| `database/seeders/` | Demo accounts for local development |
| `docker/minio/` | Locally built MinIO image and bucket init script |
| `docker/nginx/` | nginx config, including same-origin Reverb proxying |
| `docker/php/` | PHP upload limits |
| `resources/views/` | Blade layouts, messenger shell, auth pages, settings and call overlay |
| `resources/js/app.js` | UI behaviour, fetch/XHR calls and Echo subscriptions |
| `resources/js/calls/` | WebRTC engine, Alpine call store, overlay and chat call button |
| `resources/css/app.css` | Messenger layout, notes, receipts, presence, unsend menu and attachment styles |
| `resources/css/calls.css` | Call overlay, floating bar and call-history styles |
| `public/sounds/` | Small WAV cues used by message notifications |
| `docs/lan-https-setup.md` | LAN HTTPS, Caddy, mkcert and tunnel guide |
| `tests/` | Pest feature and unit tests |
| `.github/workflows/tests.yml` | CI: Pint style check and Pest suite |

### Message sound assets

The two bundled files are tiny 8-bit-style PCM WAV cues:

- `public/sounds/received.wav` — incoming message ping
- `public/sounds/sent.wav` — quieter confirmation on successful send

Replace either file with your own short WAV (or MP3/OGG and update the paths in `resources/js/app.js`) without changing PHP code. They are served from `public/`, so a normal `npm run build` is not required for a replacement; clients may need a hard refresh to bypass the browser cache. Audio is unlocked on the first click/keypress and failures are ignored silently.

---

## Realtime events

| Event | Delivered to | Purpose |
|---|---|---|
| `MessageSent` | conversation channel | New message, previews, unread counts |
| `MessageSeen` | conversation channel | Read receipts and blue ticks |
| `MessageUnsent` | conversation channel | Delete-for-everyone placeholder |
| `PresenceUpdated` | online presence + user channels | Online/last-active changes |
| `NotificationCreated` | `user.{id}` | Notification center and badges |
| `NotificationRead` | `user.{id}` | Notification read state |
| `ConversationsUpdated` | `user.{id}` | Sidebar refresh |
| `UnreadCountsUpdated` | `user.{id}` | Badge counters |
| `GroupUpdated` | conversation channel | Renames, avatars and member changes |
| `AccessRevoked` | conversation/user channels | Member removal and group deletion |
| `CallEvent` | `users.{id}` / `call.{id}` | Ringing, accept, reject, busy, end and SDP/ICE signaling |

Events use `ShouldBroadcastNow`, so delivery is immediate without a queue worker. For higher traffic, switch to `ShouldBroadcast` and run `php artisan queue:work`.

Typing indicators are whispers: browser → Reverb → other browsers. They never reach Laravel and are throttled in the client.

---

## Development and testing

```bash
# PHP tests (in-memory SQLite, fake MinIO, no Docker required)
composer test

# Style check / auto-fix
composer lint
composer format

# Style + tests
composer check

# Frontend production build
npm run build

# Frontend dev server
npm run dev
```

### Inside Docker

```bash
docker compose exec app composer test
docker compose exec app php artisan migrate:status
docker compose exec app php artisan storage:prune-orphaned-files --dry-run
docker compose logs -f reverb
docker compose logs -f minio
```

### Tests

The suite uses [Pest](https://pestphp.com) on top of PHPUnit. Feature tests run with `RefreshDatabase` against an in-memory SQLite database, `BROADCAST_CONNECTION=null`, and array cache/session/mail drivers, so no development database or Reverb server is touched. External effects are faked (`Event::fake`, `Storage::fake('minio')`, `Notification::fake`, `Carbon::setTestNow`).

```bash
# Run the whole suite
composer test

# Run one file
./vendor/bin/pest tests/Feature/UnsendPestTest.php

# Run tests whose name matches
./vendor/bin/pest --filter="rejects delete for everyone"

# Run a Pest describe group
./vendor/bin/pest --filter="delete for me"
```

Coverage includes registration/login, profile and avatar validation, direct-chat privacy, friend requests, message requests, rejection/cancellation, notes and expiry/visibility, read receipts (including groups and pagination), MinIO attachment authorization, realtime event/channel rules, notifications, group management, audio-call signaling/state/history, cascades, storage pruning and schema indexes.

Coverage requires Xdebug or PCOV (`php -m | grep -E 'xdebug|pcov'`). Neither is installed in this environment, so no `test:coverage` script is defined. Once a driver is available, use `./vendor/bin/pest --coverage --min=60`.

### Code style hook

An optional pre-commit hook formats staged PHP files with Pint. Enable it per clone:

```bash
git config core.hooksPath .githooks
```

Disable it with `git config --unset core.hooksPath`.

### Scheduled maintenance

`calls:expire` ends ringing calls that were never answered, and is registered in `routes/console.php` to run every minute. The Docker stack does **not** run a scheduler container, so start one separately in production:

```bash
php artisan schedule:work
```

or add a cron entry:

```cron
* * * * * cd /path/to/incognito-messenger && php artisan schedule:run >> /dev/null 2>&1
```

`storage:prune-orphaned-files` scans only application-owned prefixes (`avatars/` and `conversations/`) and removes objects that no profile, group or message attachment references. Run it with `--dry-run` first. Failed uploads are cleaned up immediately in the request; the command catches other orphans, such as those left by an interrupted deploy.

---

## Troubleshooting

### The page loads but messages never arrive

- Check the browser console for `[WS] connection: …`.
- In Docker, nginx proxies `/app` and `/apps` to Reverb on the page's port. Keep `REVERB_BROWSER_PORT` empty.
- For a split-port setup without a proxy, set `REVERB_BROWSER_PORT=8080`.
- Make sure `REVERB_HOST=reverb` inside Docker (not `localhost`) and `REVERB_ALLOWED_ORIGINS` includes the browser hostname.
- Inspect `docker compose logs -f reverb`.

### `cURL error 7: Failed to connect` when broadcasting

Laravel cannot reach Reverb. Inside Docker use the service name:

```dotenv
REVERB_HOST=reverb
```

### Audio calls fail or the microphone is blocked

- Use HTTPS, `http://localhost` or `http://127.0.0.1`. Plain HTTP over a LAN IP is not a secure context.
- Accept the microphone permission prompt; it appears only when a call starts or is accepted.
- Calls across different networks need a TURN relay.
- Check Settings → Calls for the microphone test and device pickers.
- With `APP_DEBUG=true`, the diagnostics panel shows ICE state and candidate type.

### Attachments fail to upload

- Laravel limits each file to 20 MB and five files per message; avatars are limited to 10 MB.
- Check that `minio` and `minio-init` completed successfully.
- Verify `MINIO_ENDPOINT`, credentials and bucket name.
- The browser downloads through Laravel, so it does not need direct access to MinIO.

### `419 Page Expired` or login loops over HTTPS

- Set `APP_URL` to the exact HTTPS origin.
- Set `SESSION_SECURE_COOKIE=true` over HTTPS.
- Set `TRUSTED_PROXIES` for the proxy in front of Laravel.
- Clear cached config after changes: `php artisan config:clear`.

### `database is locked` under call load

- Keep `CACHE_STORE=file` (or Redis), not `database`.
- Keep WAL mode and the busy timeout from `.env.example`.
- For heavier workloads, move to MySQL/PostgreSQL and Redis.

### Frontend changes do not appear

- In Docker, rebuild: `docker compose up -d --build`, which reruns the `assets` job.
- Run `npm run build` for the local setup.
- Hard-refresh the browser to bypass static asset caching.

### Changing `REVERB_APP_KEY` breaks the WebSocket

The key is baked into the JS bundle at build time. Rebuild the image after changing it:

```bash
docker compose build
docker compose up -d
```

---

## Known limitations

- **No end-to-end encryption.** The server can read messages and files. Use a hardened E2E messenger if that is a requirement.
- **Reports are not persisted.** “Report” removes the pending message request; there is no moderation queue or report history.
- **In-chat search is client-side.** It filters the messages already loaded in the open conversation, not the full server history.
- **Conversation mute is per browser.** It is stored in `localStorage`, not on the account, so it does not sync across devices.
- **Email verification is optional.** The flows exist and work, but login is not blocked until the address is verified.
- **Audio only, one-to-one.** There is no video calling or group calling.
- **The Docker stack does not run the scheduler.** Start `php artisan schedule:work` or a cron entry for call expiry and other scheduled tasks.
- **SQLite is intended for a single node.** Use MySQL/PostgreSQL and Redis for larger or multi-instance deployments.
- **Group creators can add registered users.** Group membership is not limited to the creator's friends.

---

## License

Released under the MIT license, as declared in `composer.json`.
