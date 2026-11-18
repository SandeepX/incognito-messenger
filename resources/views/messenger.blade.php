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
    <meta name="user-message-sounds" content="{{ $me->messageSoundsEnabled() ? '1' : '0' }}">
    <meta name="user-show-active-status" content="{{ $me->showActiveStatus() ? '1' : '0' }}">
    <title>Incognito Messenger</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div id="offline-banner" class="offline">Connection lost — reconnecting…</div>

<div class="app" id="app">
    <aside class="sidebar">
        <!-- Fixed header: brand, own avatar/note, search and notes strip. -->
        <div class="sidebar-fixed">
            <div class="brand-bar">
                <span class="brand-icon">🕶️</span>
                <div><strong>Incognito Messenger</strong><small>Private by design</small></div>
                <button id="friends-panel-btn" class="icon-btn friends-panel-btn people-toggle" type="button" title="Open friends panel" aria-label="Open friends panel">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M22 20v-1.5a4 4 0 0 0-3-3.87M16 3.63a4 4 0 0 1 0 7.75"/></svg>
                </button>
                <button id="notification-btn" class="icon-btn" type="button" title="Notifications" aria-label="Notifications">
                    🔔<span id="notification-badge" class="badge" hidden></span>
                </button>
            </div>

            <div class="me-card">
                <button id="me-note-avatar" class="avatar-note-trigger" type="button" title="Leave a note" aria-label="Leave a note">
                    <img id="me-avatar" class="avatar" src="{{ $me->avatarUrl() }}" alt="Your avatar">
                    <span id="me-note-bubble" class="me-note-bubble">+ Note</span>
                </button>
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
                <input id="user-search" type="text" autocomplete="off" placeholder="Find friends by name or username">
                <div id="search-results" class="search-results"></div>
            </div>

            <!-- Messenger-style Notes: only you and accepted friends appear here. -->
            <section class="notes-strip">
                <div class="section-head small">
                    <h2>Notes</h2>
                    <button id="add-note-btn" class="icon-btn" type="button" title="Leave a note" aria-label="Leave a note">＋</button>
                </div>
                <div id="notes-list" class="notes-list">
                    <span class="empty-notes">No active notes yet.</span>
                </div>
            </section>
        </div>

        <!-- Only this area scrolls; the header above stays fixed. -->
        <div class="sidebar-scroll slim-scroll">
            <!-- ---------------- Chats ---------------- -->
            <section id="pane-chats" class="list-pane">
                <div class="section-head">
                    <h2>Chats</h2>
                    <button id="new-group-btn" class="icon-btn" type="button" title="New group" aria-label="New group">＋</button>
                </div>
                <ul id="conversation-list" class="conv-list">
                    <li class="empty-list">Loading chats…</li>
                </ul>
            </section>
        </div>
    </aside>

    <main>
        <div id="empty-chat" class="empty-chat">
            <div>
                <div class="big">💬</div>
                <strong>Select a chat</strong>
                <p>Open the friends panel to start a chat, or tap ＋ for a group.</p>
            </div>
        </div>

        <section id="chat" class="chat">
            <header class="chat-head">
                <button id="chat-back" class="back-btn" type="button" aria-label="Back">‹</button>
                <span class="avatar-wrap">
                    <img id="chat-avatar" class="avatar" src="{{ asset('images/default-avatar.svg') }}" alt="">
                    <span id="chat-note-cloud" class="avatar-note" hidden></span>
                </span>
                <div class="who">
                    <strong id="chat-name">—</strong>
                    <small id="chat-subtitle"></small>
                </div>
                <div id="typing" class="typing"><span id="typing-label">typing</span><span class="dots"><i></i><i></i><i></i></span></div>
                <button id="chat-people-btn" class="icon-btn people-toggle chat-people-btn" type="button" title="Open friends panel" aria-label="Open friends panel">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20M9 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M22 20v-1.5a4 4 0 0 0-3-3.87M16 3.63a4 4 0 0 1 0 7.75"/></svg>
                </button>
                <button id="group-info-btn" class="icon-btn" type="button" title="Group settings" aria-label="Group settings" hidden>⋯</button>
            </header>

            <div id="messages" class="messages" aria-live="polite"></div>

            <form id="composer" class="composer">
                <button id="jump-latest" class="jump-latest" type="button" hidden>Jump to latest ↓</button>

                <div id="attachment-previews" class="attachment-previews" hidden></div>
                <div id="attachment-error" class="attachment-error" role="alert" hidden></div>
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
                    <button id="send-btn" class="send-btn" type="submit" aria-label="Send message" title="Send message" disabled>
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                <path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12 2-12 2z"/>
            </svg>
        </button>
                </div>
            </form>
        </section>
    </main>

    <aside id="right-panel" class="right-panel" aria-label="Friends and requests">
        <div class="right-panel-head">
            <strong>People</strong>
            <button id="right-panel-close" class="icon-btn" type="button" title="Close friends panel" aria-label="Close friends panel">✕</button>
        </div>

        <div class="right-panel-tabs" role="tablist" aria-label="People sections">
            <button id="tab-friends" class="right-tab active" type="button" role="tab" aria-selected="true" aria-controls="panel-friends">Friends</button>
            <button id="tab-requests" class="right-tab" type="button" role="tab" aria-selected="false" aria-controls="panel-requests">
                Requests<span id="right-requests-badge" class="badge" hidden></span>
            </button>
        </div>

        <div class="right-panel-scroll slim-scroll">
            <div id="panel-friends" class="right-tab-panel" role="tabpanel" aria-labelledby="tab-friends">
                <section id="pane-friends" class="list-pane">
                    <div class="section-head">
                        <h2>Your Friends</h2>
                        <span id="friends-count" class="muted"></span>
                    </div>
                    <ul id="friend-list" class="conv-list compact-list"><li class="empty-list">Loading friends…</li></ul>
                </section>
            </div>

            <div id="panel-requests" class="right-tab-panel" role="tabpanel" aria-labelledby="tab-requests" hidden>
                <section id="pane-friend-requests" class="list-pane">
                    <div class="section-head">
                        <h2>Friend Requests</h2>
                        <span class="head-meta">
                            <span id="friend-incoming-count" class="muted"></span>
                            <button id="find-friends-btn" class="right-add-btn" type="button" title="Send a friend request" aria-label="Send a friend request">＋ Send request</button>
                        </span>
                    </div>
                    <ul id="friend-request-list" class="conv-list compact-list"></ul>

                    <div class="section-head"><h2>Sent Friend Requests</h2></div>
                    <ul id="friend-sent-list" class="conv-list compact-list"></ul>
                </section>

                <section id="pane-message-requests" class="list-pane">
                    <div class="section-head">
                        <h2>Message Requests</h2>
                        <span class="head-meta">
                            <span id="message-request-count" class="muted"></span>
                            <span id="request-badge" class="badge" hidden></span>
                        </span>
                    </div>
                    <ul id="message-request-list" class="conv-list compact-list"><li class="empty-list">No message requests.</li></ul>

                    <div class="section-head"><h2>Sent Message Requests</h2></div>
                    <ul id="message-sent-list" class="conv-list compact-list"></ul>
                </section>
            </div>
        </div>
    </aside>
</div>

<!-- Find friends (right-panel modal) -->
<div id="find-friends-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2>Find friends</h2>
            <button class="icon-btn" type="button" data-close="find-friends-modal" aria-label="Close">✕</button>
        </div>
        <label for="friend-search">Search by name or username</label>
        <input id="friend-search" class="inline-search" type="text" autocomplete="off" placeholder="Find friends…">
        <label for="friend-request-note">Note (optional)</label>
        <textarea id="friend-request-note" maxlength="160" rows="2" placeholder="Say hello — you can leave this blank"></textarea>
        <div id="friend-results" class="search-results static-results"></div>
    </div>
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
        <textarea id="note-content" maxlength="60" rows="3" placeholder="A short note — up to 60 characters, gone after 24 hours"></textarea>
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
