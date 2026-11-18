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
            <button id="notification-btn" class="icon-btn" type="button" title="Notifications" aria-label="Notifications">
                🔔<span id="notification-badge" class="badge" hidden></span>
            </button>
        </div>

        <!-- Small-screen entry points; harmless on desktop where the right
             panel is already visible. -->
        <div class="left-shortcuts">
            <button id="friends-shortcut" class="shortcut-btn" type="button">
                👥 Friends<span id="friend-shortcut-badge" class="badge" hidden></span>
            </button>
            <button id="requests-shortcut" class="shortcut-btn" type="button">
                ✉️ Requests<span id="request-shortcut-badge" class="badge" hidden></span>
            </button>
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

        <!-- Messenger-style Notes: only you and accepted friends appear here. -->
        <section class="notes-strip">
            <div class="section-head small">
                <h2>Notes</h2>
                <button id="add-note-btn" class="icon-btn" type="button" title="Add a note" aria-label="Add a note">＋</button>
            </div>
            <div id="notes-list" class="notes-list">
                <span class="empty-notes">No active notes yet.</span>
            </div>
        </section>

        <!-- ---------------- Conversations ---------------- -->
        <div id="pane-chats" class="list-pane">
            <div class="section-head">
                <h2>Conversations</h2>
                <button id="new-group-btn" class="icon-btn" type="button" title="New group" aria-label="New group">＋</button>
            </div>
            <ul id="conversation-list" class="conv-list">
                <li class="empty-list">Loading conversations…</li>
            </ul>
        </div>
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
                <span class="avatar-wrap">
                    <img id="chat-avatar" class="avatar" src="{{ asset('images/default-avatar.svg') }}" alt="">
                    <span id="chat-note-orb" class="note-orb" title="Active note" hidden>☁</span>
                </span>
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

                <div id="attachment-previews" class="attachment-previews" hidden></div>
                <div id="upload-progress" class="upload-progress" hidden><div class="bar"></div></div>

                <div class="composer-tools">
                    <button id="emoji-btn" class="icon-btn" type="button" title="Emoji" aria-label="Emoji">😊</button>
                    <button id="attach-btn" class="icon-btn" type="button" title="Attach a file" aria-label="Attach a file">📎</button>
                    <input id="attachment-input" type="file" multiple hidden
                           accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                    <div id="emoji-picker" class="emoji-picker" hidden></div>
                </div>

                <div class="composer-row">
                    <textarea id="message-input" rows="1" placeholder="Write a message…" autocomplete="off"></textarea>
                    <button id="send-btn" class="send-btn" type="submit">Send</button>
                </div>
            </form>
            <div class="composer-hint">Enter to send · Shift + Enter for a new line · Files up to 20 MB</div>
        </section>
    </main>

    <!-- ---------------- Right rail: friends + requests ---------------- -->
    <aside class="right-panel" id="right-panel">
        <div class="right-head">
            <button id="right-back" class="back-btn" type="button" aria-label="Back to chats">‹</button>
            <div class="tabs" role="tablist">
                <button class="tab active" type="button" data-tab="friends" role="tab">Friends</button>
                <button class="tab" type="button" data-tab="requests" role="tab">
                    Requests<span id="request-badge" class="badge" hidden></span>
                </button>
            </div>
        </div>

        <div id="pane-friends" class="list-pane right-pane">
            <div class="section-head"><h2>Friends</h2><span id="friends-count" class="muted"></span></div>
            <ul id="friend-list" class="conv-list compact-list"><li class="empty-list">Loading friends…</li></ul>

            <div class="section-head"><h2>Friend Requests</h2><span id="friend-incoming-count" class="muted"></span></div>
            <ul id="friend-request-list" class="conv-list compact-list"></ul>

            <div class="section-head"><h2>Sent Requests</h2></div>
            <ul id="friend-sent-list" class="conv-list compact-list"></ul>

            <div class="section-head"><h2>Find People</h2></div>
            <input id="friend-search" class="inline-search" type="text" autocomplete="off" placeholder="Search registered users">
            <div id="friend-results" class="search-results static-results"></div>
        </div>

        <div id="pane-requests" class="list-pane right-pane" hidden>
            <div class="section-head"><h2>Message Requests</h2><span id="message-request-count" class="muted"></span></div>
            <ul id="message-request-list" class="conv-list compact-list"><li class="empty-list">No message requests.</li></ul>

            <div class="section-head"><h2>Sent Requests</h2></div>
            <ul id="message-sent-list" class="conv-list compact-list"></ul>
        </div>
    </aside>
</div>

<!-- New group -->
<div id="group-modal" class="backdrop">
    <div class="modal">
        <h2>Create a group</h2>
        <form id="group-form" enctype="multipart/form-data">
            <label for="group-name">Group name</label>
            <input id="group-name" type="text" maxlength="80" required placeholder="e.g. Weekend trip">

            <label for="group-avatar">Group avatar (optional — JPEG, PNG, GIF or WebP, max 10 MB)</label>
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
        <div id="add-member-results" class="search-results static-results"></div>

        <div class="modal-actions">
            <button id="leave-group-btn" class="btn danger" type="button">Leave group</button>
            <button id="delete-group-btn" class="btn danger" type="button" hidden>Delete group</button>
        </div>
    </div>
</div>

<!-- Note editor / viewer -->
<div id="note-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2 id="note-modal-title">Your note</h2>
            <button class="icon-btn" type="button" data-close="note-modal" aria-label="Close">✕</button>
        </div>
        <div id="note-author" class="member-row" hidden></div>
        <textarea id="note-content" maxlength="300" rows="4" placeholder="What's on your mind? (disappears after 24 hours)"></textarea>
        <div class="modal-actions">
            <button id="note-save-btn" class="btn primary" type="button">Save note</button>
            <button id="note-delete-btn" class="btn danger" type="button" hidden>Delete note</button>
            <button class="btn" type="button" data-close="note-modal">Close</button>
        </div>
    </div>
</div>

<!-- Message request preview -->
<div id="request-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2>Message request</h2>
            <button class="icon-btn" type="button" data-close="request-modal" aria-label="Close">✕</button>
        </div>
        <div id="request-details" class="request-details"></div>
        <textarea id="request-body" rows="3" maxlength="2000" placeholder="Write a short introduction…" hidden></textarea>
        <div id="request-messages" class="request-messages"></div>
        <div class="modal-actions">
            <button id="request-send-btn" class="btn primary" type="button" hidden>Send request</button>
            <button id="request-accept-btn" class="btn primary" type="button" hidden>Accept</button>
            <button id="request-delete-btn" class="btn danger" type="button" hidden>Delete</button>
            <button id="request-cancel-btn" class="btn danger" type="button" hidden>Cancel request</button>
        </div>
    </div>
</div>

<!-- Notifications -->
<div id="notifications-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2>Notifications</h2>
            <button class="icon-btn" type="button" data-close="notifications-modal" aria-label="Close">✕</button>
        </div>
        <div id="notifications-list" class="members"></div>
        <div class="modal-actions">
            <button id="notifications-read-btn" class="btn primary" type="button">Mark all as read</button>
        </div>
    </div>
</div>

<!-- Image lightbox -->
<div id="lightbox" class="backdrop lightbox">
    <button class="lightbox-close" type="button" data-close="lightbox" aria-label="Close">✕</button>
    <img id="lightbox-image" src="" alt="Image preview">
    <a id="lightbox-download" class="btn primary" href="#" download>Download</a>
</div>

<div id="toast" class="toast"></div>
</body>
</html>
