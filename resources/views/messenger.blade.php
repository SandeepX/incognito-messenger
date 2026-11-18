<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (auth()->check())
        <meta name="user-id" content="{{ auth()->id() }}">
        <meta name="user-name" content="{{ auth()->user()->name }}">
    @endif
    <title>WebSocket Messenger</title>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; height: 100vh; overflow: hidden; }

        .app { display: flex; height: 100vh; }

        /* ---------- sidebar ---------- */
        aside { width: 290px; background: #1e293b; border-right: 1px solid #334155; display: flex; flex-direction: column; }
        .side-head { padding: 14px 16px; border-bottom: 1px solid #334155; }
        .side-head h1 { font-size: 16px; }
        .side-head .me { font-size: 12.5px; color: #94a3b8; margin-top: 4px; }
        .side-head nav { margin-top: 8px; font-size: 13px; }
        .side-head nav a { color: #7dd3fc; text-decoration: none; margin-right: 10px; }
        .side-title { padding: 10px 16px 6px; font-size: 11.5px; letter-spacing: .08em; text-transform: uppercase; color: #64748b; }
        #online-list { list-style: none; overflow-y: auto; flex: 1; }
        #online-list li { padding: 10px 16px; cursor: pointer; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #1e293b; font-size: 14px; }
        #online-list li:hover { background: #273449; }
        #online-list li.active { background: #0ea5e9; color: #fff; }
        #online-list li .dot { width: 9px; height: 9px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 6px #4ade80; flex: none; }
        #online-list li.self { cursor: default; opacity: .65; }
        #online-list li.self:hover { background: none; }
        #online-list .muted { color: #64748b; font-style: italic; cursor: default; }

        /* ---------- conversation ---------- */
        main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        #empty { flex: 1; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
        .conv { display: none; flex-direction: column; height: 100%; }
        .conv.open { display: flex; }
        .conv-head { padding: 12px 20px; background: #1e293b; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 12px; }
        .conv-head h2 { font-size: 15px; }
        .typing { margin-left: auto; font-size: 12.5px; color: #7dd3fc; visibility: hidden; display: flex; align-items: center; gap: 8px; }
        .typing.show { visibility: visible; }
        .dots { display: inline-flex; gap: 3px; }
        .dots i { width: 6px; height: 6px; border-radius: 50%; background: #7dd3fc; animation: bounce 1.2s infinite; }
        .dots i:nth-child(2) { animation-delay: .15s; }
        .dots i:nth-child(3) { animation-delay: .3s; }
        @keyframes bounce { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-5px); opacity: 1; } }

        #msgs { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 8px; }
        .msg { max-width: 65%; padding: 9px 13px; border-radius: 14px; font-size: 14px; line-height: 1.45; word-break: break-word; }
        .msg.theirs { align-self: flex-start; background: #1e293b; border: 1px solid #334155; border-bottom-left-radius: 4px; }
        .msg.mine { align-self: flex-end; background: #0ea5e9; color: #fff; border-bottom-right-radius: 4px; }

        form.compose { display: flex; gap: 10px; padding: 14px 20px; background: #1e293b; border-top: 1px solid #334155; }
        form.compose input { flex: 1; font-size: 14px; border-radius: 10px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; padding: 11px 14px; outline: none; }
        form.compose input:focus { border-color: #0ea5e9; }
        form.compose button { font-size: 14px; border: none; background: #0ea5e9; color: #fff; padding: 0 20px; border-radius: 10px; cursor: pointer; font-weight: 600; }
        form.compose button:hover { background: #38bdf8; }

        /* ---------- guest overlay ---------- */
        #guest { position: fixed; inset: 0; background: rgba(15,23,42,.92); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; z-index: 10; }
        #guest h1 { font-size: 20px; }
        #guest p { color: #94a3b8; font-size: 14px; }
        #guest a { display: inline-block; background: #0ea5e9; color: #fff; padding: 10px 22px; border-radius: 10px; text-decoration: none; font-weight: 600; margin: 0 6px; }
        #guest a:hover { background: #38bdf8; }
    </style>
</head>
<body>
<div class="app">
    <aside>
        <div class="side-head">
            <h1>💬 WebSocket Messenger</h1>
            <div class="me">@auth Logged in as <strong>{{ auth()->user()->name }}</strong> @endauth</div>
            <nav>
                <a href="/login/alice">Alice</a>
                <a href="/login/bob">Bob</a>
                <a href="/login/charlie">Charlie</a>
                @auth<form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button style="background:none;border:none;color:#7dd3fc;cursor:pointer;font-size:13px">Logout</button></form>@endauth
            </nav>
        </div>
        <div class="side-title">Online now</div>
        <ul id="online-list">
            <li class="muted">connecting…</li>
        </ul>
    </aside>

    <main>
        <div id="empty">Select someone from the online list to start chatting →</div>
        <div class="conv" id="conv">
            <div class="conv-head">
                <h2 id="conv-name"></h2>
                <div class="typing" id="typing">
                    <span id="typing-name"></span> is typing
                    <span class="dots"><i></i><i></i><i></i></span>
                </div>
            </div>
            <div id="msgs"></div>
            <form class="compose" id="compose">
                <input id="compose-input" placeholder="Message…" autocomplete="off">
                <button type="submit">Send</button>
            </form>
        </div>
    </main>
</div>

<div id="guest" @if (auth()->check()) hidden @endif>
    <h1>💬 WebSocket Messenger</h1>
    <p>Pick a demo account to enter — open a second browser/incognito window as another user to chat.</p>
    <div>
        <a href="/login/alice">Enter as Alice</a>
        <a href="/login/bob">Enter as Bob</a>
        <a href="/login/charlie">Enter as Charlie</a>
    </div>
</div>

<script>
    window.userId = document.querySelector('meta[name="user-id"]')
        ? Number(document.querySelector('meta[name="user-id"]').content)
        : null;
    window.myName = document.querySelector('meta[name="user-name"]')
        ? document.querySelector('meta[name="user-name"]').content
        : 'Guest';
</script>
@vite(['resources/js/app.js'])
</body>
</html>
