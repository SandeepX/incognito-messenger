<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ $me->id }}">
    <meta name="user-name" content="{{ $me->name }}">
    <meta name="user-username" content="{{ $me->username }}">
    <meta name="user-avatar" content="{{ $me->avatarUrl() }}">
    <title>Incognito Messenger</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div id="offline-banner" class="offline">Connection lost — reconnecting…</div>

<div class="app" id="app">
    <aside class="sidebar">
        <div class="brand-bar">
            <span class="brand-icon">🕶️</span>
            <div><strong>Incognito Messenger</strong><small>Private by design</small></div>
        </div>

        <div class="me-card">
            <img class="avatar" src="{{ $me->avatarUrl() }}" alt="Your avatar">
            <div class="who">
                <strong>{{ $me->name }}</strong>
                <small>{{ '@'.$me->username }}</small>
            </div>
            <a class="icon-btn" href="{{ route('profile.edit') }}" title="Edit profile" aria-label="Edit profile">⚙</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout" type="submit" title="Log out" aria-label="Log out">⏻</button>
            </form>
        </div>

        <div class="search-wrap">
            <input id="user-search" type="text" autocomplete="off" placeholder="Search people by name or username">
            <div id="search-results" class="search-results"></div>
        </div>

        <div class="section-head">
            <h2>Conversations</h2>
            <button id="new-group-btn" class="icon-btn" type="button" title="New group" aria-label="New group">＋</button>
        </div>
        <ul id="conversation-list" class="conv-list">
            <li class="empty-list">Loading conversations…</li>
        </ul>
    </aside>

    <main>
        <div id="empty-chat" class="empty-chat">
            <div>
                <div class="big">💬</div>
                <strong>Select a conversation</strong>
                <p>Search for someone above, or tap ＋ to start a group.</p>
            </div>
        </div>

        <section id="chat" class="chat">
            <header class="chat-head">
                <button id="chat-back" class="back-btn" type="button" aria-label="Back">‹</button>
                <img id="chat-avatar" class="avatar" src="{{ asset('images/default-avatar.svg') }}" alt="">
                <div class="who">
                    <strong id="chat-name">—</strong>
                    <small id="chat-subtitle"></small>
                </div>
                <div id="typing" class="typing"><span id="typing-label">typing</span><span class="dots"><i></i><i></i><i></i></span></div>
                <button id="group-info-btn" class="icon-btn" type="button" title="Group settings" aria-label="Group settings" hidden>⋯</button>
            </header>

            <div id="messages" class="messages" aria-live="polite"></div>

            <form id="composer" class="composer">
                <button id="jump-latest" class="jump-latest" type="button" hidden>Jump to latest ↓</button>
                <textarea id="message-input" rows="1" placeholder="Write a message…" autocomplete="off"></textarea>
                <button id="send-btn" class="send-btn" type="submit">Send</button>
            </form>
            <div class="composer-hint">Enter to send · Shift + Enter for a new line</div>
        </section>
    </main>
</div>

<!-- New group -->
<div id="group-modal" class="backdrop">
    <div class="modal">
        <h2>Create a group</h2>
        <form id="group-form" enctype="multipart/form-data">
            <label for="group-name">Group name</label>
            <input id="group-name" type="text" maxlength="80" required placeholder="e.g. Weekend trip">

            <label for="group-avatar">Group avatar (optional)</label>
            <input id="group-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">

            <label for="group-member-search">Add members</label>
            <input id="group-member-search" type="text" autocomplete="off" placeholder="Search registered users">
            <div id="group-members" class="members"></div>

            <div class="modal-actions">
                <button class="btn primary" type="submit">Create group</button>
                <button class="btn" type="button" data-close="group-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Group settings -->
<div id="settings-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2 id="settings-title">Group settings</h2>
            <button class="icon-btn" type="button" data-close="settings-modal" aria-label="Close">✕</button>
        </div>
        <div id="settings-members" class="members"></div>

        <label for="add-member-search">Add a member</label>
        <input id="add-member-search" type="text" autocomplete="off" placeholder="Search users">
        <div id="add-member-results" class="search-results" style="position:static;margin-top:6px"></div>

        <div class="modal-actions">
            <button id="leave-group-btn" class="btn danger" type="button">Leave group</button>
            <button id="delete-group-btn" class="btn danger" type="button" hidden>Delete group</button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>
</body>
</html>
