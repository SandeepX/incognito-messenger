# WebSocket Messenger — Laravel + Reverb

A small demo for understanding **how WebSockets actually work** in Laravel, using the official **Laravel Reverb** WebSocket server and **Laravel Echo** on the frontend. The UI is a messenger: see who's online, open a 1:1 conversation, watch the "typing…" dots, and exchange messages that appear live on both sides.

## The big picture

A normal HTTP request is *client asks → server answers → done*. The server cannot start talking later. A WebSocket is a **long-lived connection** opened once (HTTP `Upgrade` handshake) and kept open, so **the server can push data to the browser at any moment**.

```
  Alice's browser                    Laravel                         Reverb
 ┌──────────────┐   POST /messages  ┌───────────────┐               ┌──────────────┐
 │ type + Enter │ ────────────────► │ MessageController             │              │
 │              │                   │ MessageSent::dispatch()       │              │
 │              │                   │   │ broadcasted on            │              │
 │              │   ws://host:8080  │   └── private-chat.1.2 ─────► │ WS server    │
 │ ◄───────────────────────────────────────────── push event ─────── │              │
 │  own bubble  │                   │                               │  ─────────►  │ Bob's browser
 └──────────────┘                   └───────────────┘               └──────────────┘
                                                                           ▲
   "Alice is typing…" ───── a CLIENT event (whisper), browser → Reverb ─────┘
                          does NOT touch Laravel at all
```

Key players:

| Piece | Role |
|---|---|
| **Reverb** (`php artisan reverb:start`) | A standalone PHP WebSocket server. Holds open connections, relays events, checks channel subscriptions. Listens on port `8080`. |
| **Echo** (`resources/js/echo.js`) | Browser library that opens the WebSocket, subscribes to channels, and lets you write `listen('.Event', ...)` / `whisper(...)`. |
| **Events** (`app/Events/*`) | Plain PHP classes marked `ShouldBroadcastNow`. Their public properties become the JSON payload pushed to clients. |
| **`routes/channels.php`** | Decides *who* is allowed on private/presence channels. |
| **Laravel app** | Your normal HTTP app. It doesn't hold WebSockets itself — it *hands* events to Reverb. |

## What the app does

1. **Online sidebar — presence channel.** `Echo.join('online')` subscribes to the wire channel `presence-online`. `here()`, `joining()` and `leaving()` maintain the list of online users. (Echo adds the `presence-` prefix itself — passing `'presence-online'` would produce `presence-presence-online`.)
2. **1:1 conversations — private channel.** Each pair uses a *symmetric* channel `chat.{minId}.{maxId}`. Both participants subscribe to the **same** channel; `routes/channels.php` lets only those two ids in.
3. **Messages — server event.** `POST /messages` saves the message and dispatches `MessageSent`. Reverb pushes it to both subscribers — including the sender, so own messages use the same render path as received ones.
4. **Typing indicator — client event (whisper).** `Echo.private(channel).whisper('typing', ...)` goes browser → Reverb → the other browser. No controller, no event class, no HTTP request — that's why it feels instant.
5. **History — plain HTTP.** `GET /messages/{user}` backfills past messages when a conversation is opened; WebSockets are *live only*.

## Run it (Docker)

```bash
cd ~/websocket-demo
docker compose up -d --build
```

That single command runs the whole system:

| Service | Container job | Port |
|---|---|---|
| `migrate` (one-shot) | Creates the schema + seeds Alice/Bob/Charlie/Dana, then exits | — |
| `assets` (one-shot) | Copies built JS/CSS from the image to a volume nginx mounts | — |
| `app` | PHP-FPM running Laravel | internal |
| `webserver` | Nginx → FastCGI → `app` | **http://localhost:8000** |
| `reverb` | The WebSocket server | **ws://localhost:8080** |

Two things to notice (they're the two most common Docker mistakes with Reverb):

- **Inside the Docker network**, Laravel pushes events to `http://reverb:8080` (`REVERB_HOST=reverb` is overridden in `docker-compose.yml`), while the **browser** connects to `ws://localhost:8080` because that port is published from the `reverb` container. Same server, two views of the network. If the app used `localhost:8080` inside a container it would talk to itself and fail with cURL error 7.
- The `VITE_REVERB_*` vars are baked into the JS bundle **at image build time**, so a `REVERB_APP_KEY` change requires `docker compose build`.

If you change PHP code after building, run `docker compose up -d --build app` and then `docker compose restart webserver` (nginx caches the PHP-FPM container's old IP until restarted).

Stop with `docker compose down`. The SQLite data lives in the `db-data` volume and survives rebuilds; wipe it with `docker compose down -v`.

## Accessing from other machines

Docker publishes both ports on `0.0.0.0`, so any device on the network can open:

```
http://<server-ip>:8000
```

Find the server IP with `hostname -I` (e.g. `http://192.168.120.112:8000`).

`resources/js/echo.js` derives the WebSocket host from `window.location.hostname` instead of baking `localhost` into the bundle, so a browser on another machine automatically connects to `ws://<server-ip>:8080` — same build, no recompilation, no per-machine config. (If it were hardcoded to `localhost`, the remote browser would try to open the WebSocket against *itself* and silently fail.)

If it still doesn't connect from another machine, check the server's firewall allows ports 8000 (nginx) and 8080 (Reverb).

## Try it

Open **http://localhost:8000** in two browsers (or one normal + one incognito window). Log in as **Alice** in one and **Bob** in the other (shortcut links are in the sidebar, or use `/login/alice`, `/login/bob`, etc.).

- Bob appears in Alice's **Online now** list the moment his WebSocket subscribes; he disappears when he closes the tab.
- Click **Bob** in the sidebar, type a message and press Enter. It appears in both windows instantly.
- While someone types, the other window shows **"Bob is typing"** with animated dots — from a whisper that never reaches Laravel.
- Watch the browser **console** (`F12`): `connecting → connected`, plus `[presence]` join/leave logs.
- Watch the **Reverb** logs: `docker compose logs -f reverb` — it logs connections, subscriptions and events.
- Kill Reverb (`docker compose stop reverb`): Echo retries and recovers when it's back (`docker compose start reverb`).

## Where to look in the code

| File | What it teaches |
|---|---|
| `app/Events/MessageSent.php` | A broadcast event: payload (`broadcastWith`), custom name (`broadcastAs`) and the pair-sorted `PrivateChannel` |
| `app/Http/Controllers/MessageController.php` | Where `dispatch()` happens, plus HTTP history shaped exactly like the event payload so one render path handles both |
| `routes/channels.php` | Authorization rules for the presence channel and the symmetric `chat.{a}.{b}` channel |
| `resources/js/app.js` | `join()` (presence), `private()` + `listen()` (messages), `listenForWhisper()` and `whisper()` (typing) |
| `resources/views/messenger.blade.php` | The UI and the CSS for the animated typing dots |
| `resources/js/echo.js` | How the browser connects to Reverb, deriving the host from the page URL (LAN-safe) |
| `docker-compose.yml` | How the same image becomes app / reverb / nginx, and the `REVERB_HOST=reverb` trick |
| `Dockerfile` | Node stage builds Echo JS; PHP-FPM stage runs the app |

## Notes

- Events use `ShouldBroadcastNow` (broadcast immediately, no queue) to keep the demo simple. In production use `ShouldBroadcast` and run `php artisan queue:work` — broadcasts then travel via the queue and never block web requests.
- Client events (whispers) only work on **private/presence** channels: Reverb authenticates the subscription first, so random visitors can't inject events into someone else's conversation.
- The two participants' ids are sorted in both the event and the client so both sides land on the same channel name (`chat.1.2`, never `chat.2.1`).
- Every user id in a conversation is checked in `routes/channels.php` — a third user who guesses `chat.1.2` gets a `403` from `/broadcasting/auth`.
