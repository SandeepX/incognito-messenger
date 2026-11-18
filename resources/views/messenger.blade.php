<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-id" content="{{ $me->id }}">
    <meta name="user-name" content="{{ $me->name }}">
    <meta name="user-username" content="{{ $me->username }}">
    <meta name="user-avatar" content="{{ $me->avatarUrl() ?? '' }}">
    <meta name="user-message-sounds" content="{{ $me->messageSoundsEnabled() ? '1' : '0' }}">
    <meta name="user-message-sounds-volume" content="{{ $me->messageSoundsVolume() }}">
    <meta name="user-desktop-notifications" content="{{ $me->desktopNotificationsEnabled() ? '1' : '0' }}">
    <meta name="user-show-active-status" content="{{ $me->showActiveStatus() ? '1' : '0' }}">
    <meta name="user-read-receipts" content="{{ $me->readReceiptsEnabled() ? '1' : '0' }}">
    <meta name="user-theme-preference" content="{{ $me->themePreference() }}">
    <title>Incognito Messenger</title>
    <script>
        (function () {
            try {
                var id = document.querySelector('meta[name="user-id"]').content;
                var server = document.querySelector('meta[name="user-theme-preference"]')?.content || 'system';
                var key = 'incognito-theme-' + id;
                var pref = localStorage.getItem(key) || server || 'system';
                if (pref !== 'light' && pref !== 'dark' && pref !== 'system') pref = 'system';
                try { localStorage.setItem(key, pref); } catch (e) {}
                var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                document.documentElement.dataset.themePreference = pref;
            } catch (e) {
                document.documentElement.dataset.theme = 'dark';
                document.documentElement.dataset.themePreference = 'system';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div id="offline-banner" class="offline" role="status">Connection lost — reconnecting…</div>

<div class="app" id="app">
    <aside class="sidebar" aria-label="Sidebar">
        <div class="sidebar-fixed">
            <div class="brand-bar">
                <span class="brand-icon" aria-hidden="true">🕶️</span>
                <div class="brand-copy"><strong>Incognito Messenger</strong><small>Private by design</small></div>
                <button id="notification-btn" class="icon-btn" type="button" title="Notifications" aria-label="Notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span id="notification-badge" class="badge" hidden></span>
                </button>
            </div>

            <div class="me-card">
                <img id="me-avatar" class="avatar" src="{{ $me->avatarUrl() ?? '' }}" alt="" data-name="{{ $me->name }}" data-username="{{ $me->username }}">
                <div class="who">
                    <strong>{{ $me->name }}</strong>
                    <small>{{ '@'.$me->username }}</small>
                </div>
                <div class="menu-wrap">
                    <button id="me-menu-btn" class="icon-btn" type="button" title="Menu" aria-label="Open menu" aria-expanded="false" aria-haspopup="menu">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div id="me-menu" class="dropdown-menu" role="menu" hidden>
                        <a class="menu-item" href="{{ route('profile.edit') }}" role="menuitem">Settings</a>
                        <form method="POST" action="{{ route('logout') }}" class="menu-form">
                            @csrf
                            <button class="menu-item danger" type="submit" role="menuitem">Log out</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="search-wrap">
                <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input id="chat-search" type="text" autocomplete="off" placeholder="Search chats" aria-label="Search chats">
            </div>

            <section class="notes-strip" aria-label="Notes">
                <div id="notes-list" class="notes-list">
                    <div class="skeleton-notes" aria-hidden="true">
                        <span class="skeleton circle"></span><span class="skeleton circle"></span><span class="skeleton circle"></span><span class="skeleton circle"></span>
                    </div>
                </div>
            </section>

            <div class="sidebar-tabs" role="tablist" aria-label="Sidebar sections">
                <button id="side-tab-chats" class="side-tab active" type="button" role="tab" aria-selected="true" aria-controls="side-pane-chats">
                    <svg class="tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
                    <span class="tab-label">Chats</span>
                </button>
                <button id="side-tab-requests" class="side-tab" type="button" role="tab" aria-selected="false" aria-controls="side-pane-requests">
                    <svg class="tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>
                    <span class="tab-label">Requests</span>
                    <span id="side-requests-badge" class="badge tab-badge" hidden></span>
                </button>
                <button id="side-tab-friends" class="side-tab" type="button" role="tab" aria-selected="false" aria-controls="side-pane-friends">
                    <svg class="tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="tab-label">Friends</span>
                </button>
            </div>
        </div>

        <div class="sidebar-scroll slim-scroll">
            <section id="side-pane-chats" class="side-pane" role="tabpanel" aria-labelledby="side-tab-chats">
                <div class="section-head">
                    <h2>Chats</h2>
                    <div class="menu-wrap">
                        <button id="sidebar-add-btn" class="icon-btn" type="button" title="New" aria-label="New chat or group" aria-expanded="false" aria-haspopup="menu">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                        <div id="sidebar-add-menu" class="dropdown-menu" role="menu" hidden>
                            <button id="find-friends-open-btn" class="menu-item" type="button" role="menuitem">Find friends</button>
                            <button id="new-group-btn" class="menu-item" type="button" role="menuitem">New group</button>
                        </div>
                    </div>
                </div>
                <ul id="conversation-list" class="conv-list" aria-label="Conversations">
                    <li class="skeleton-chat"><span class="skeleton circle"></span><div class="sk-lines"><span class="skeleton line w70"></span><span class="skeleton line w50"></span></div></li>
                    <li class="skeleton-chat"><span class="skeleton circle"></span><div class="sk-lines"><span class="skeleton line w60"></span><span class="skeleton line w40"></span></div></li>
                    <li class="skeleton-chat"><span class="skeleton circle"></span><div class="sk-lines"><span class="skeleton line w70"></span><span class="skeleton line w55"></span></div></li>
                </ul>
            </section>

            <section id="side-pane-requests" class="side-pane requests-pane" role="tabpanel" aria-labelledby="side-tab-requests" hidden>
                <div class="requests-segmented" role="tablist" aria-label="Request views">
                    <button id="requests-subtab-received" class="seg-btn active" type="button" role="tab" aria-selected="true" aria-controls="requests-panel-received">
                        <span>Received</span>
                        <span id="requests-received-badge" class="badge tab-badge" hidden></span>
                    </button>
                    <button id="requests-subtab-sent" class="seg-btn" type="button" role="tab" aria-selected="false" aria-controls="requests-panel-sent">
                        <span>Sent</span>
                    </button>
                </div>

                <div id="requests-panel-received" class="requests-panel" role="tabpanel" aria-labelledby="requests-subtab-received">
                    <div id="requests-received-loading" class="requests-loading" hidden aria-hidden="true"></div>
                    <div id="requests-received-error" class="requests-state requests-error" hidden>
                        <p class="requests-state-text">Couldn’t load requests.</p>
                        <button id="requests-received-retry" class="mini-btn" type="button">Retry</button>
                    </div>
                    <div id="requests-received-empty" class="requests-state requests-empty" hidden>
                        <div class="requests-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>
                        </div>
                        <p class="requests-state-title">No requests right now</p>
                        <button id="requests-received-find" class="btn primary btn-sm" type="button">Find friends</button>
                    </div>
                    <div id="requests-received-content" class="requests-content"></div>
                </div>

                <div id="requests-panel-sent" class="requests-panel" role="tabpanel" aria-labelledby="requests-subtab-sent" hidden>
                    <div id="requests-sent-loading" class="requests-loading" hidden aria-hidden="true"></div>
                    <div id="requests-sent-error" class="requests-state requests-error" hidden>
                        <p class="requests-state-text">Couldn’t load requests.</p>
                        <button id="requests-sent-retry" class="mini-btn" type="button">Retry</button>
                    </div>
                    <div id="requests-sent-empty" class="requests-state requests-empty" hidden>
                        <div class="requests-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>
                        </div>
                        <p class="requests-state-title">No requests right now</p>
                        <button id="requests-sent-find" class="btn primary btn-sm" type="button">Find friends</button>
                    </div>
                    <div id="requests-sent-content" class="requests-content"></div>
                </div>
            </section>

            <section id="side-pane-friends" class="side-pane" role="tabpanel" aria-labelledby="side-tab-friends" hidden>
                <div class="section-head">
                    <h2>Your Friends</h2>
                    <span id="friends-count" class="muted"></span>
                </div>
                <ul id="friend-list" class="conv-list compact-list"><li class="empty-list">Loading friends…</li></ul>
            </section>
        </div>
    </aside>

    <main>
        <div id="empty-chat" class="empty-chat">
            <div class="empty-chat-card">
                <div class="big" aria-hidden="true">💬</div>
                <strong>Say hi to your friends</strong>
                <p>Select a chat from the sidebar or find someone new to start a conversation.</p>
            </div>
        </div>

        <section id="chat" class="chat" aria-label="Conversation">
            <header class="chat-head">
                <button id="chat-back" class="back-btn" type="button" aria-label="Back to chats">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <span class="avatar-wrap chat-avatar-wrap">
                    <img id="chat-avatar" class="avatar" src="" alt="" data-name="">
                </span>
                <div class="who">
                    <strong id="chat-name">—</strong>
                    <small id="chat-subtitle"></small>
                </div>
                <div class="chat-actions">
                    <div id="chat-search-wrap" class="chat-search-wrap" hidden>
                        <input id="chat-search-input" type="text" placeholder="Search in chat" aria-label="Search in chat">
                        <button id="chat-search-close" class="icon-btn" type="button" title="Close search" aria-label="Close search">✕</button>
                    </div>
                    <button id="chat-search-btn" class="icon-btn" type="button" title="Search in chat" aria-label="Search in chat">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </button>
                    <button id="chat-info-btn" class="icon-btn" type="button" title="Profile / info" aria-label="Profile or conversation info">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                    </button>
                    <button id="chat-mute-btn" class="icon-btn" type="button" title="Mute conversation" aria-label="Mute conversation" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 8.5a5 5 0 0 1 0 7M17.5 5.5a9 9 0 0 1 0 13M6 9H4a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2l4 4V5z"/></svg>
                    </button>
                    <button id="group-info-btn" class="icon-btn" type="button" title="Group settings" aria-label="Group settings" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                </div>
            </header>

            <div id="messages" class="messages" aria-live="polite"></div>

            <form id="composer" class="composer">
                <button id="jump-latest" class="jump-latest" type="button" hidden>Jump to latest ↓</button>

                <div id="attachment-previews" class="attachment-previews" hidden></div>
                <div id="attachment-error" class="attachment-error" role="alert" hidden></div>
                <div id="upload-progress" class="upload-progress" hidden><div class="bar"></div></div>

                <div class="composer-bar">
                    <button id="emoji-btn" class="icon-btn composer-icon" type="button" title="Emoji" aria-label="Emoji">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/></svg>
                    </button>
                    <button id="attach-btn" class="icon-btn composer-icon" type="button" title="Attach a file" aria-label="Attach a file">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 1 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    </button>
                    <textarea id="message-input" rows="1" placeholder="Write a message…" autocomplete="off" aria-label="Message"></textarea>
                    <button id="send-btn" class="send-btn" type="submit" aria-label="Send message" title="Send message" disabled>
                        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
                            <path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12 2-12 2z"/>
                        </svg>
                    </button>
                </div>
                <input id="attachment-input" type="file" multiple hidden
                       accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                <div id="emoji-picker" class="emoji-picker" hidden></div>
            </form>
        </section>
    </main>
</div>

<!-- Find friends modal -->
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

<!-- Message request composer (start a request from Find friends) -->
<div id="request-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2>Send a message request</h2>
            <button class="icon-btn" type="button" data-close="request-modal" aria-label="Close">✕</button>
        </div>
        <div id="request-details" class="request-details"></div>
        <textarea id="request-body" rows="3" maxlength="2000" placeholder="Write a short introduction…"></textarea>
        <div class="modal-actions">
            <button id="request-send-btn" class="btn primary" type="button">Send request</button>
        </div>
    </div>
</div>

<!-- Chat info / profile -->
<div id="chat-info-modal" class="backdrop">
    <div class="modal">
        <div class="row-between"><h2 id="chat-info-title">Info</h2>
            <button class="icon-btn" type="button" data-close="chat-info-modal" aria-label="Close">✕</button>
        </div>
        <div id="chat-info-body" class="info-body"></div>
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

<div id="toast" class="toast" role="status" aria-live="polite"><span id="toast-message"></span><button id="toast-action" class="toast-action" type="button" hidden></button></div>
</body>
</html>
