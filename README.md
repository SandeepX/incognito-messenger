# Incognito Messenger

A real-time messenger built with **Laravel 13**, **Laravel Reverb**, **Laravel Echo** and **MinIO**. It supports registration/login, profiles, friend requests, message requests, direct and group chats, read receipts, private file sharing, 24-hour notes, notifications and a Messenger-style UI. Every HTTP request and WebSocket subscription is authorized on the backend.

## Features

- **Accounts & profiles** — registration, login/logout, throttling, editable name/email/username/bio/status and avatars.
- **Friend graph** — send/accept/reject/cancel friend requests; only accepted friends appear in the Friends tab and may start a normal direct chat. One pending request per pair.
- **Message requests** — non-friends can send a short introduction with one message. The thread is hidden from normal chat lists until the recipient accepts; accepting also creates the friendship. The recipient alone can delete an incoming request; the sender alone can cancel one.
- **Direct & group messages** — one conversation per pair, cursor-paginated history, latest-message previews, unread badges, typing whispers and presence.
- **Read receipts** — sent → delivered → seen. A `message_reads` row is written only when a recipient actually opens the thread (never from a websocket receipt alone). Group messages show every reader, and receipts are included in every paginated message payload.
- **Private media & files** — images (JPEG/PNG/GIF/WebP) and documents (PDF, Word, Excel, TXT, CSV, ZIP) up to 20 MB each, max 5 per message. Everything is stored in a private MinIO bucket with generated names. Download/preview URLs are signed, expire after 5 minutes, and are generated only after a policy check.
- **Notes** — one active 300-character note per user, shown as a cloud in the notes strip, visible only to the author and accepted friends, automatically hidden after 24 hours, with owner-only edit/delete and a small cloud marker on avatars when someone has a note.
- **Realtime** — new messages, read receipts, friend/message request events, accepts, notes, unread counts, conversation-list changes and group changes are pushed over Reverb. Request and notification counters update without a refresh.
- **Authorization** — policies plus `routes/channels.php` decide who may read, write, download or subscribe. Channel authorization is re-checked live for groups, so removed members lose access immediately.
- **Responsive UI** — chats stay in the left rail while Friends and Requests live in a right rail on desktop; they become a slide-over on smaller screens, where the chat list still drills down to a full chat. Includes an emoji picker, attachment progress/previews, an image lightbox and a notification center.

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

`MINIO_ENDPOINT` is the server-to-server address used inside Docker (`http://minio:9000`); `MINIO_URL` is the browser-visible **origin only** (no bucket path) used to sign temporary URLs. The `minio-public` disk reuses the same bucket but signs with that public origin so links work from the browser. Never put `MINIO_SECRET_KEY` in a `VITE_*` variable.

## API overview

All routes below require the session cookie and CSRF header.

| Area | Endpoints |
|---|---|
| Friends | `GET/POST /api/friends`, `POST /api/friends/{request}/accept`, `POST /api/friends/{request}/reject`, `DELETE /api/friends/{request}`, `DELETE /api/friends/user/{user}` |
| Message requests | `GET/POST /api/message-requests`, `GET /api/message-requests/{request}`, `POST /api/message-requests/{request}/accept`, `POST /api/message-requests/{request}/delete`, `DELETE /api/message-requests/{request}` |
| Notes | `GET/POST /api/notes`, `GET/PUT/DELETE /api/notes/{note}` |
| Notifications | `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all` |
| Attachments | `GET /api/attachments/{id}/download`, `GET /api/attachments/{id}/preview` |
| Chat | `GET /api/conversations`, `GET /api/conversations/{id}`, `POST /api/conversations/direct/{user}`, `POST /api/conversations/{id}/messages` |

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
| `app/Models/` | Users, profiles, conversations, groups, messages, reads, friends, requests, notes, notifications, attachments |
| `app/Policies/` | Backend authorization rules for every owned resource |
| `app/Http/Controllers/` | Auth, profile, avatar, chat, group, friend, request, note, notification and attachment controllers |
| `app/Events/` | `MessageSent`, `MessageSeen`, `NotificationCreated`, `ConversationsUpdated`, `UnreadCountsUpdated`, `AccessRevoked`, `GroupUpdated` |
| `app/Support/` | `UnreadCounter`, `PairKey`, `Notifier`, `AttachmentUpload`, `ReverbConnectionTerminator` |
| `app/Console/Commands/PruneOrphanedFiles.php` | Removes MinIO objects with no database row |
| `database/migrations/` | Full schema, indexes, foreign keys and cascades |
| `docker/minio/` | Locally built MinIO server image and bucket init script |
| `resources/js/app.js` | UI behaviour, fetch/XHR calls and Echo subscriptions |
| `resources/css/app.css` | Messenger layout, notes, receipts and attachment styles |

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

Tests cover registration/login, profile and avatar validation, direct-chat privacy, friend requests, message requests, rejection/cancellation, notes/expiry/visibility, read receipts (including groups and pagination), MinIO attachment authorization, realtime event/channel rules, notifications, group management, cascades, storage pruning and schema indexes.

## Notes

- Events use `ShouldBroadcastNow` so delivery is immediate without a queue worker. For high traffic, switch to `ShouldBroadcast` and run `php artisan queue:work`.
- Typing indicators are whispers: browser → Reverb → other browsers. They never reach Laravel and are throttled in the client.
- The WebSocket key is baked into the JS bundle at build time; changing `REVERB_APP_KEY` requires `docker compose build`.
- Avatar uploads are capped at 10 MB and message attachments at 20 MB; nginx and PHP are configured for 28 MB request bodies so multipart overhead does not truncate valid uploads.
