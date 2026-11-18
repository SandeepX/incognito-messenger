import './echo';

/*
 * Incognito Messenger — browser application.
 *
 * HTTP (fetch)  : search, history, sending, group management
 * WebSocket     : presence, live messages, unread badges, typing whispers
 *
 * Every private channel is authorized by Laravel in routes/channels.php;
 * hiding a UI control is never used as an access-control mechanism.
 */

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const $ = (id) => document.getElementById(id);
const csrf = () => meta('csrf-token');
const flag = (name, fallback = true) => {
    const value = meta(name);
    return value == null ? fallback : value === '1' || value === 'true';
};

const state = {
    me: {
        id: Number(meta('user-id')),
        name: meta('user-name'),
        username: meta('user-username'),
        avatar: meta('user-avatar'),
    },
    online: new Map(),
    presence: new Map(),
    settings: {
        messageSounds: flag('user-message-sounds'),
        showActiveStatus: flag('user-show-active-status'),
    },
    conversations: [],
    active: null,          // {id, type, group_id, peer_id, name, ...}
    activeChannel: null,
    activeGroup: null,
    cursor: null,
    loadingOlder: false,
    unseen: 0,              // messages received while scrolled up
    typingTimer: null,
    lastWhisperSent: 0,
    selectedMembers: new Set(),
    settingsGroupId: null,
    friends: [],
    friendRequests: { incoming: [], outgoing: [] },
    messageRequests: { incoming: [], outgoing: [] },
    notes: [],
    activeNote: null,
    currentRequest: null,
    pendingAttachments: [],
    notificationCount: 0,
};

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[c]));

const debounce = (fn, ms) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
};

/* A broken avatar must never render its raw alt text. Swap it for a stable,
   coloured circle derived from the person's name instead. */
function avatarFallbackSrc(name) {
    const raw = String(name ?? '').trim();
    const initial = (Array.from(raw)[0] ?? '?').toUpperCase().replace(/[<>&"']/g, '');
    const palette = ['#0ea5e9', '#8b5cf6', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#f59e0b'];
    let hash = 0;
    for (const char of raw) hash = (hash * 31 + char.codePointAt(0)) >>> 0;
    const color = palette[hash % palette.length];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="${color}"/><text x="48" y="61" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="44" font-weight="600" fill="#fff">${initial}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function applyAvatarFallback(img) {
    if (img.src.startsWith('data:image/svg+xml')) return;
    const rowName = img.closest('.conv-item, .msg-row, .member-row, .note-card, .search-results button')
        ?.querySelector('.conv-name, .who strong, strong')?.textContent?.trim();
    const name = img.dataset.name || img.alt || rowName || state.me.name || '?';
    img.dataset.name = name;
    img.src = avatarFallbackSrc(name);
}

// Image error events do not bubble, so listen in the capture phase.
document.addEventListener('error', (event) => {
    const img = event.target;
    if (img instanceof HTMLImageElement && img.classList.contains('avatar')) {
        applyAvatarFallback(img);
    }
}, true);

async function api(url, options = {}) {
    const { headers = {}, ...rest } = options;

    const response = await fetch(url, {
        ...rest,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...headers,
        },
    });

    if (! response.ok) {
        let message = `Request failed (${response.status})`;
        try {
            const body = await response.json();
            message = body.message ?? message;
        } catch { /* keep the generic message */ }
        throw new Error(message);
    }

    return response.status === 204 ? null : response.json();
}

function toast(message) {
    const el = $('toast');
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => el.classList.remove('show'), 3200);
}

function formatTime(value) {
    if (! value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function formatShortTime(value) {
    if (! value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const today = new Date();
    if (date.toDateString() === today.toDateString()) return formatTime(value);
    return date.toLocaleDateString([], { month: 'numeric', day: 'numeric' });
}

function relativeActive(value, now = Date.now()) {
    if (! value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    const seconds = Math.max(0, Math.floor((now - date.getTime()) / 1000));
    if (seconds < 60) return 'Active now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `Active ${minutes} min ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `Active ${hours} ${hours === 1 ? 'hr' : 'hrs'} ago`;

    const days = Math.floor(hours / 24);
    if (days < 7) return `Active ${days} ${days === 1 ? 'day' : 'days'} ago`;

    // Never-seen and older-than-a-week users show no status at all.
    return '';
}

/** Presence is hidden entirely when the other person disabled the setting. */
function isOnline(userId) {
    return presenceInfo(userId).online;
}

function presenceInfo(userId) {
    const id = Number(userId);
    if (! id) return { online: false, label: '', dot: '' };

    const member = state.online.get(id);
    const record = state.presence.get(id);
    const hidden = member?.show_active_status === false || record?.show_active_status === false;
    if (hidden) return { online: false, label: '', dot: '' };

    const online = state.online.has(id);
    const lastActiveAt = record?.last_active_at ?? member?.last_active_at ?? null;
    const label = online ? 'Active now' : relativeActive(lastActiveAt);
    const dot = label ? `<span class="status-dot ${online ? 'online' : 'recent'}"></span>` : '';

    return { online, label, dot };
}

function updatePresenceUI() {
    renderConversationList();
    updateChatHeader();
    document.querySelectorAll('[data-presence-for]').forEach((element) => {
        element.textContent = presenceInfo(element.dataset.presenceFor).label;
    });
    document.querySelectorAll('[data-friend-presence-for]').forEach((element) => {
        const presence = presenceInfo(element.dataset.friendPresenceFor);
        element.innerHTML = `<span class="status-dot ${presence.online ? 'online' : ''}"></span>${presence.online ? 'Online' : 'Offline'}`;
    });
}

/* ---------------- small notification sounds ---------------- */

const sounds = {};
try {
    for (const [kind, path] of Object.entries({
        received: '/sounds/received.wav',
        sent: '/sounds/sent.wav',
    })) {
        sounds[kind] = new Audio(path);
        sounds[kind].preload = 'auto';
    }
} catch { /* audio is a nice-to-have, never a hard failure */ }

function playSound(kind) {
    if (! state.settings.messageSounds) return;
    const sound = sounds[kind];
    if (! sound) return;

    try {
        sound.currentTime = 0;
        const promise = sound.play();
        if (promise?.catch) promise.catch(() => {});
    } catch { /* autoplay blocked or unsupported — stay silent */ }
}

function unlockAudio() {
    Object.values(sounds).forEach((sound) => {
        try {
            const volume = sound.volume;
            sound.volume = 0;
            const promise = sound.play();
            if (promise?.catch) promise.catch(() => {});
            sound.pause();
            sound.currentTime = 0;
            sound.volume = volume;
        } catch { /* ignore */ }
    });
}

['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
    document.addEventListener(eventName, function unlockOnce() {
        unlockAudio();
        ['pointerdown', 'keydown', 'touchstart'].forEach((other) => {
            document.removeEventListener(other, unlockOnce, true);
        });
    }, { capture: true, once: true });
});

function shouldPlayReceivedSound(message) {
    if (! state.settings.messageSounds) return false;
    if (message.sender_id === state.me.id || message.is_unsent) return false;

    // Reading an incoming message in the focused, active chat is not a
    // notification. Background tabs and other conversations still ping.
    const activeChatFocused = state.active?.id === message.conversation_id
        && document.visibilityState === 'visible'
        && document.hasFocus();

    return ! activeChatFocused;
}

/* Inline icons keep the UI dependency-free and sharp at any density. */
const ICON_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.4 7.6L3 20.5l1.4-5.1A8.5 8.5 0 1 1 21 11.5Z"/></svg>';
const ICON_TRASH = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/></svg>';
const ICON_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5 9 17.5 20 6.5"/></svg>';
const ICON_DOUBLE_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12.5 7 17.5 17.5 7"/><path d="M9 12.5 14 17.5 22 8.5"/></svg>';

/** Sent = one grey tick; Seen = two blue ticks. Never a text status. */
function statusTicksHtml(message) {
    if (message.sender_id !== state.me.id || message.is_unsent) return '';

    const seen = message.status === 'seen' && (message.seen_by ?? []).length > 0;
    const label = seen ? 'Seen' : 'Sent';

    return `<span class="status-ticks${seen ? ' seen' : ''}" data-status-ticks="${message.id}"
                role="img" aria-label="${label}" title="${label}">
                ${seen ? ICON_DOUBLE_CHECK : ICON_CHECK}<span class="sr-only">${label}</span>
            </span>`;
}

function setStatusTicks(row, seen) {
    const holder = row?.querySelector('[data-status-ticks]');
    if (! holder) return;
    const label = seen ? 'Seen' : 'Sent';
    holder.classList.toggle('seen', seen);
    holder.setAttribute('aria-label', label);
    holder.setAttribute('title', label);
    holder.innerHTML = `${seen ? ICON_DOUBLE_CHECK : ICON_CHECK}<span class="sr-only">${label}</span>`;
}

/** Avatar + a tiny thought cloud that carries the active note text. */
function noteCloudHtml(note, size = '') {
    const text = typeof note === 'string' ? note.trim() : '';
    if (! text) return '';

    return `<span class="avatar-note${size === 'sm' ? ' sm' : ''}" title="${esc(text)}">
        <span class="avatar-note-text">${esc(text)}</span>
    </span>`;
}

function avatarWithNote(avatar, note, size = '', name = '') {
    const label = name ? `${name}'s avatar` : 'Avatar';
    return `<span class="avatar-wrap">${noteCloudHtml(note, size)}<img class="avatar${size ? ' '+size : ''}" src="${esc(avatar)}" alt="${esc(label)}" data-name="${esc(name)}"></span>`;
}

function applyUnreadCounts(counts) {
    if (! counts) return;

    state.conversations.forEach((conversation) => {
        conversation.unread = counts[conversation.id] ?? 0;
    });
    renderConversationList();
}

function isNearBottom(threshold = 80) {
    const box = $('messages');
    return box.scrollHeight - box.scrollTop - box.clientHeight <= threshold;
}

function updateJumpLatest() {
    const box = $('messages');
    const button = $('jump-latest');
    const scrollable = box.scrollHeight - box.clientHeight > 24;

    button.hidden = ! scrollable || isNearBottom();
    button.textContent = state.unseen > 0
        ? `New messages (${state.unseen}) ↓`
        : 'Jump to latest ↓';
}

function scrollBottom(smooth = false) {
    const box = $('messages');

    if (typeof box.scrollTo === 'function') {
        box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    } else {
        box.scrollTop = box.scrollHeight;
    }

    state.unseen = 0;
    updateJumpLatest();
}

/**
 * Normal messages sit at the bottom. A message taller than the viewport is
 * aligned to the TOP instead, so its first line is readable immediately
 * (the rest can be scrolled normally inside the message pane).
 */
function scrollToLatestRow(smooth = false) {
    const box = $('messages');
    const rows = box.querySelectorAll('.msg-row');
    const last = rows[rows.length - 1];

    if (! last || last.getBoundingClientRect().height <= box.clientHeight - 24) {
        scrollBottom(smooth);
        return;
    }

    const top = last.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop;
    box.scrollTo({ top: Math.max(0, top - 8), behavior: smooth ? 'smooth' : 'auto' });
    state.unseen = 0;
    updateJumpLatest();
}

/* ================================================================
 * Presence + per-user notifications
 * ================================================================ */

async function heartbeat() {
    try {
        await api('/api/presence/heartbeat', { method: 'POST' });
    } catch { /* transient presence failures are invisible to the user */ }
}

async function loadPresence() {
    try {
        const users = await api('/api/presence');
        users.forEach((user) => state.presence.set(Number(user.id), user));
        updatePresenceUI();
    } catch { /* keep the last known relative times */ }
}

window.Echo.join('online')
    .here((users) => {
        state.online = new Map(users.map((u) => [Number(u.id), u]));
        // Joining the presence channel is a real socket connect: persist it.
        heartbeat();
        updatePresenceUI();
    })
    .joining((user) => {
        state.online.set(Number(user.id), user);
        updatePresenceUI();
    })
    .leaving((user) => {
        state.online.delete(Number(user.id));
        updatePresenceUI();
    });

window.Echo.connector.pusher.connection.bind('connected', () => heartbeat());

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        heartbeat();
        loadPresence();
    }
});

window.addEventListener('pagehide', () => disconnectPresence());

function disconnectPresence() {
    if (! navigator.sendBeacon) return;
    try {
        navigator.sendBeacon(
            '/api/presence/disconnect',
            new URLSearchParams({ _token: csrf() }),
        );
    } catch { /* best-effort only; the timestamp naturally expires */ }
}

setInterval(() => {
    heartbeat();
    loadPresence();
}, 30000);

window.Echo.private(`user.${state.me.id}`)
    .listen('.UnreadCountsUpdated', (event) => {
        applyUnreadCounts(event.counts);
    })
    .listen('.ConversationsUpdated', () => {
        loadConversations();
    })
    .listen('.PresenceUpdated', (event) => {
        if (! event.user) return;
        const id = Number(event.user.id);
        state.presence.set(id, event.user);

        if (event.online) {
            state.online.set(id, event.user);
        } else {
            state.online.delete(id);
        }

        updatePresenceUI();
    })
    .listen('.NotificationCreated', (event) => {
        state.notificationCount = event.unread_count ?? state.notificationCount + 1;
        updateNotificationBadge();
        toast(notificationText(event));
        if ($('notifications-modal').classList.contains('show')) loadNotifications();
        // Friend/request/note counters and avatar clouds can all change here.
        loadFriends();
        loadMessageRequests();
        loadNotes();
        loadConversations();
    });

window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    const offline = states.current !== 'connected';
    $('offline-banner').classList.toggle('show', offline);
    if (states.current === 'connected' && states.previous !== 'connected') {
        // Echo resubscribes by itself; only our HTTP-derived data is stale.
        loadConversations();
    }
});

/* ================================================================
 * Conversation list
 * ================================================================ */

async function loadConversations() {
    try {
        state.conversations = await api('/api/conversations');

        // Keep the open header in sync when a note appears/disappears.
        if (state.active) {
            const fresh = state.conversations.find((c) => c.id === state.active.id);
            if (fresh) {
                state.active.note = fresh.note;
                state.active.avatar = fresh.avatar;
                updateChatHeader();
            }
        }

        renderConversationList();
    } catch (error) {
        toast(error.message);
    }
}

function renderConversationList() {
    const list = $('conversation-list');

    if (! state.conversations.length) {
        list.innerHTML = '<li class="empty-list">No chats yet.<br>Find friends above to start chatting.</li>';
        return;
    }

    list.innerHTML = state.conversations.map((c) => {
        const last = c.last_message;
        const preview = ! last
            ? 'No messages yet'
            : last.is_unsent
                ? (last.preview || 'Message unsent')
                : `${last.sender_id === state.me.id ? 'You: ' : ''}${last.body || (last.attachments?.length ? `📎 ${last.attachments[0].original_filename}` : '')}`;
        const presence = c.type === 'direct' ? presenceInfo(c.peer_id) : { label: '', dot: '' };

        return `
            <li class="conv-item ${state.active?.id === c.id ? 'active' : ''}" data-id="${c.id}">
                ${avatarWithNote(c.avatar, c.note, '', c.name)}
                <div class="body">
                    <div class="conv-row">
                        <span class="conv-name">${presence.dot}${esc(c.name)}${presence.label ? `<span class="conv-presence">${esc(presence.label)}</span>` : ''}</span>
                        <span class="conv-time">${esc(formatShortTime(c.last_at))}</span>
                    </div>
                    <div class="conv-bottom">
                        <span class="conv-preview">${esc(preview)}</span>
                        <span class="badge">${c.unread ? esc(c.unread) : ''}</span>
                    </div>
                </div>
            </li>`;
    }).join('');
}

$('conversation-list').addEventListener('click', (event) => {
    const item = event.target.closest('.conv-item');
    if (item) openConversation(Number(item.dataset.id));
});

$('chat-back').addEventListener('click', () => {
    $('app').classList.remove('show-chat');
});

/* ================================================================
 * User search → start a direct conversation
 * ================================================================ */

function renderUserResults(users, container, onPick) {
    container.innerHTML = '';

    if (! users.length) {
        container.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:12.5px">No users found.</div>';
        return;
    }

    users.forEach((user) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = `
            ${avatarWithNote(user.avatar, user.note, 'sm', user.name)}
            <span class="who"><strong>${esc(user.name)}</strong><small>@${esc(user.username)}</small></span>`;
        button.addEventListener('click', () => onPick(user));
        container.appendChild(button);
    });
}

$('user-search').addEventListener('input', debounce(async (event) => {
    const query = event.target.value.trim();
    try {
        const users = await api(`/api/users?q=${encodeURIComponent(query)}`);
        renderUserResults(users, $('search-results'), async (user) => {
            $('search-results').innerHTML = '';
            $('user-search').value = '';
            try {
                const isFriend = user.is_friend ?? state.friends.some((friend) => friend.id === user.id);

                if (! isFriend) {
                    // Non-friends may never open a normal thread — only a request.
                    if (user.conversation_id) {
                        toast('You are not friends yet. Accept or send a message request first.');
                        return;
                    }
                    openRequestComposer(user);
                    return;
                }

                let conversationId = user.conversation_id;
                if (! conversationId) {
                    const result = await api(`/api/conversations/direct/${user.id}`, { method: 'POST' });
                    conversationId = result.id;
                    await loadConversations();
                }
                await openConversation(conversationId);
            } catch (error) {
                toast(error.message);
            }
        });
    } catch (error) {
        toast(error.message);
    }
}, 250));

/* ================================================================
 * Conversation view + live events
 * ================================================================ */

function updateChatHeader() {
    if (! state.active) return;

    $('chat-name').textContent = state.active.name ?? 'Conversation';
    const presence = state.active.type === 'direct' ? presenceInfo(state.active.peer_id) : null;
    $('chat-subtitle').innerHTML = state.active.type === 'group'
        ? esc(state.active.subtitle ?? 'Group conversation')
        : `<span class="status-dot ${presence.online ? 'online' : ''}"></span>${esc(presence.label || 'Offline')}`;
    $('chat-avatar').dataset.name = state.active.name ?? '';
    $('chat-avatar').src = state.active.avatar ?? '/images/default-avatar.svg';

    const note = state.active.type === 'direct' ? state.active.note : null;
    const cloud = $('chat-note-cloud');
    cloud.hidden = ! note;
    cloud.innerHTML = note ? `<span class="avatar-note-text">${esc(note)}</span>` : '';
    cloud.title = note ?? '';

    $('group-info-btn').hidden = state.active.type !== 'group';
}

function attachmentHtml(message) {
    const items = message.attachments ?? [];
    if (! items.length) return '';

    return `<div class="msg-attachments">${items.map((a) => {
        if (a.attachment_type === 'image') {
            return `<img class="msg-image" data-preview="${esc(a.preview_url)}" data-download="${esc(a.download_url)}"
                        src="${esc(a.preview_url)}" alt="${esc(a.original_filename)}" loading="lazy">`;
        }
        return `<div class="file-card">
                    <span>📄</span>
                    <span class="body"><span class="file-name">${esc(a.original_filename)}</span><br>
                        <span class="file-size">${esc(a.size_label)} · ${esc(a.mime_type)}</span></span>
                    <a href="${esc(a.download_url)}" download title="Download">⬇</a>
                </div>`;
    }).join('')}</div>`;
}

function statusHtml(message) {
    return statusTicksHtml(message);
}

function unsentPreview(message) {
    if (Number(message.unsent_by) === state.me.id) return 'You unsent a message';
    return message.preview || `${message.sender_name ?? 'Someone'} unsent a message`;
}

function buildMessageRow(message) {
    const mine = message.sender_id === state.me.id;
    const unsent = message.is_unsent === true;
    const row = document.createElement('div');
    row.className = `msg-row ${mine ? 'mine' : 'theirs'}`;
    row.dataset.id = message.id ?? '';
    row.innerHTML = `
        <img class="avatar sm" src="${esc(message.sender_avatar)}" alt="${esc(message.sender_name)}'s avatar" data-name="${esc(message.sender_name)}">
        <div>
            ${unsent
                ? `<div class="unsent-bubble">${esc(unsentPreview(message))}</div>`
                : `${message.body ? `<div class="bubble">${esc(message.body)}</div>` : ''}${attachmentHtml(message)}`}
            <span class="msg-meta">${esc(message.sender_name)} · ${esc(message.time ?? formatTime(message.created_at))}</span>
            ${unsent ? '' : statusHtml(message)}
        </div>
        ${mine && ! unsent ? `<span class="msg-actions"><button class="msg-menu-btn" type="button" data-unsend-id="${message.id}" aria-label="Unsend message" title="Unsend message">⋯</button></span>` : ''}`;
    return row;
}

/** Apply the server's unsend decision; safe to call from HTTP and socket. */
function applyUnsent(event) {
    const id = Number(event.id ?? event.message_id);
    if (! id) return;

    const hiddenFor = (event.hidden_for ?? []).map(Number);
    const row = document.querySelector(`.msg-row[data-id="${id}"]`);

    if (hiddenFor.includes(state.me.id)) {
        row?.remove();
    } else if (event.mode === 'everyone' && event.message) {
        const replacement = buildMessageRow(event.message);
        if (row) row.replaceWith(replacement);
    }

    // Both the placeholder text and the sorted last message can change.
    clearTimeout(applyUnsent.reloadTimer);
    applyUnsent.reloadTimer = setTimeout(loadConversations, 80);
}

function appendMessage(message, scroll = true) {
    const row = buildMessageRow(message);
    $('messages').appendChild(row);
    if (scroll) scrollToLatestRow();
    return row;
}

function prependMessages(messages) {
    const box = $('messages');
    const fragment = document.createDocumentFragment();
    messages.forEach((message) => fragment.appendChild(buildMessageRow(message)));
    box.insertBefore(fragment, box.firstChild);
}

/** Cursor pagination: load the previous page when the user reaches the top. */
async function loadOlderMessages() {
    if (state.loadingOlder || ! state.active || ! state.cursor) {
        return;
    }

    const conversationId = state.active.id;
    const cursor = state.cursor;
    state.loadingOlder = true;

    try {
        const page = await api(`/api/conversations/${conversationId}?cursor=${encodeURIComponent(cursor)}`);
        if (state.active?.id !== conversationId) return;

        const box = $('messages');
        const previousHeight = box.scrollHeight;
        const previousTop = box.scrollTop;

        // API returns newest-first; prepend in chronological order.
        prependMessages([...page.data].reverse());

        // Keep the same message under the user's eyes after prepending.
        box.scrollTop = previousTop + (box.scrollHeight - previousHeight);
        state.cursor = page.next_cursor;
        updateJumpLatest();
    } catch (error) {
        toast(error.message);
    } finally {
        state.loadingOlder = false;
    }
}

$('messages').addEventListener('scroll', () => {
    if (isNearBottom()) {
        state.unseen = 0;
    }
    if ($('messages').scrollTop <= 60 && state.cursor) {
        loadOlderMessages();
    }
    updateJumpLatest();
}, { passive: true });

$('jump-latest').addEventListener('click', () => {
    scrollToLatestRow(true);
    $('message-input').focus();
});

function showTyping() {
    $('typing-label').textContent = `${state.active?.name ?? 'Someone'} is typing`;
    $('typing').classList.add('show');
}

function hideTyping() {
    $('typing').classList.remove('show');
    clearTimeout(state.typingTimer);
}

function handleAccessRevoked(conversationId) {
    if (state.active?.id !== conversationId) return;

    if (state.activeChannel) window.Echo.leave(state.activeChannel);
    state.activeChannel = null;
    state.active = null;
    state.activeGroup = null;
    $('chat').classList.remove('open');
    $('app').classList.remove('show-chat');
    $('empty-chat').style.display = '';
    $('settings-modal').classList.remove('show');
    toast('You no longer have access to this conversation.');
    loadConversations();
}

async function openConversation(id) {
    if (state.active?.id === id) {
        showChatView();
        return;
    }

    if (state.activeChannel) {
        window.Echo.leave(state.activeChannel);
        state.activeChannel = null;
    }

    const conversation = state.conversations.find((c) => c.id === id);
    state.active = conversation ? { ...conversation } : { id, type: 'direct' };
    state.activeGroup = null;
    state.cursor = null;
    state.loadingOlder = false;
    state.unseen = 0;

    $('empty-chat').style.display = 'none';
    $('chat').classList.add('open');
    showChatView();
    $('messages').innerHTML = '';
    $('jump-latest').hidden = true;
    $('message-input').value = '';
    updateSendButton();
    hideTyping();
    updateChatHeader();
    renderConversationList();

    if (conversation) {
        conversation.unread = 0;
        renderConversationList();
    }

    try {
        const history = await api(`/api/conversations/${id}`);
        if (state.active?.id !== id) return; // user switched while loading
        state.cursor = history.next_cursor;

        // The HTTP response carries the authoritative counts, so a missed
        // websocket event can never leave a stale badge in the chat list.
        if (history.counts) {
            applyUnreadCounts(history.counts);
        } else if (conversation) {
            conversation.unread = history.unread ?? 0;
            renderConversationList();
        }

        [...history.data].reverse().forEach((message) => appendMessage(message, false));
        scrollToLatestRow();
    } catch (error) {
        toast(error.message);
    }

    const channel = `conversation.${id}`;
    state.activeChannel = channel;

    window.Echo.private(channel)
        .listen('.MessageSent', (message) => {
            if (shouldPlayReceivedSound(message)) playSound('received');
            if (state.active?.id !== message.conversation_id) return;

            const wasAtBottom = isNearBottom();
            const mine = message.sender_id === state.me.id;

            appendMessage(message, false);

            // Own messages always jump to the latest. Incoming messages only
            // do so when the reader was already at the bottom; otherwise show
            // a jump button so older messages stay readable.
            if (mine || wasAtBottom) {
                scrollToLatestRow();
            } else {
                state.unseen += 1;
                updateJumpLatest();
            }

            hideTyping();
        })
        .listen('.MessageSeen', (event) => {
            // The reader's own receipt clears the sidebar badge immediately.
            // This runs even when the row is not rendered (e.g. another tab).
            if (Number(event.user_id) === state.me.id) {
                if (event.counts) {
                    applyUnreadCounts(event.counts);
                } else {
                    const own = state.conversations.find((c) => c.id === event.conversation_id);
                    if (own && own.unread) {
                        own.unread = 0;
                        renderConversationList();
                    }
                }
                return;
            }

            const reader = {
                user_id: event.user_id,
                name: event.name,
                avatar: event.avatar,
                read_at: event.read_at,
            };

            const row = [...$('messages').querySelectorAll('.msg-row')]
                .find((el) => Number(el.dataset.id) === Number(event.message_id));
            if (row) setStatusTicks(row, true);

            // Keep the cached conversation preview in sync without a refresh.
            const conversation = state.conversations.find((c) => c.id === event.conversation_id);
            if (conversation?.last_message?.id === event.message_id) {
                const readers = conversation.last_message.seen_by ?? [];
                if (! readers.some((seen) => seen.user_id === event.user_id)) {
                    conversation.last_message.status = 'seen';
                    conversation.last_message.seen_by = [...readers, reader];
                    renderConversationList();
                }
            }
        })
        .listen('.MessageUnsent', (event) => {
            // No sound: an unsent placeholder is not a new message.
            applyUnsent(event);
        })
        .listenForWhisper('typing', (event) => {
            if (state.active?.id !== id || event.from === state.me.id) return;
            showTyping();
            clearTimeout(state.typingTimer);
            state.typingTimer = setTimeout(hideTyping, 2200);
        })
        .listen('.GroupUpdated', (event) => {
            const local = state.conversations.find((c) => c.id === id);
            if (local && event.name) {
                local.name = event.name;
                local.avatar = event.avatar;
                local.subtitle = `${event.member_count} members`;
                if (state.active?.id === id) {
                    state.active.name = event.name;
                    state.active.avatar = event.avatar;
                    state.active.subtitle = local.subtitle;
                    updateChatHeader();
                }
                renderConversationList();
            }
            if (state.settingsGroupId === event.group_id) loadGroupSettings(event.group_id);
        })
        .listen('.AccessRevoked', (event) => {
            // Removing one member must not kick everyone else out.
            if (Number(event.user_id) === state.me.id) {
                handleAccessRevoked(id);
            }
        });

    if (conversation?.type === 'group') {
        loadGroupSettings(conversation.group_id);
    }
}

/* ================================================================
 * Composing + typing whispers
 * ================================================================ */

function updateSendButton() {
    const input = $('message-input');
    const hasContent = Boolean(input?.value.trim()) || state.pendingAttachments.length > 0;
    $('send-btn').disabled = ! state.active || ! hasContent;
}

function showAttachmentError(message) {
    const box = $('attachment-error');
    box.textContent = message;
    box.hidden = false;
}

function clearAttachmentError() {
    const box = $('attachment-error');
    box.textContent = '';
    box.hidden = true;
}

/** XHR gives us real upload progress; fetch does not. */
function uploadMessage(formData) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `/api/conversations/${state.active.id}/messages`);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());

        xhr.upload.addEventListener('progress', (event) => {
            if (! event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            $('upload-progress').hidden = false;
            $('upload-progress').querySelector('.bar').style.width = `${percent}%`;
        });

        xhr.addEventListener('load', () => {
            $('upload-progress').hidden = true;
            $('upload-progress').querySelector('.bar').style.width = '0%';
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(JSON.parse(xhr.responseText));
            } else {
                let message = `Request failed (${xhr.status})`;
                try {
                    const errorBody = JSON.parse(xhr.responseText);
                    const firstError = Object.values(errorBody.errors ?? {})[0]?.[0];
                    message = firstError ?? errorBody.message ?? message;
                } catch { /* ignore */ }
                reject(new Error(message));
            }
        });
        xhr.addEventListener('error', () => {
            $('upload-progress').hidden = true;
            reject(new Error('Upload failed. Check your connection and try again.'));
        });

        xhr.send(formData);
    });
}

$('composer').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (! state.active) return;

    const input = $('message-input');
    const body = input.value.trim();
    if (! body && ! state.pendingAttachments.length) return;

    const files = [...state.pendingAttachments];
    input.value = '';
    input.style.height = 'auto';
    state.pendingAttachments = [];
    clearAttachmentError();
    renderAttachmentPreviews();

    const data = new FormData();
    if (body) data.set('body', body);
    files.forEach((file) => data.append('attachments[]', file));

    try {
        // Our own bubble arrives back over the WebSocket, just like everyone
        // else's — one render path for both sides.
        await uploadMessage(data);
        playSound('sent');
        updateSendButton();
    } catch (error) {
        if (body) input.value = body;
        state.pendingAttachments = files;
        renderAttachmentPreviews();
        if (/larger than 20 MB/i.test(error.message)) {
            showAttachmentError("File can't be larger than 20 MB");
        } else {
            toast(error.message);
        }
    }
});

$('message-input').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && ! event.shiftKey) {
        event.preventDefault();
        $('composer').requestSubmit();
    }
});

$('message-input').addEventListener('input', (event) => {
    const wasAtBottom = isNearBottom();
    updateSendButton();

    event.target.style.height = 'auto';
    event.target.style.height = `${Math.min(event.target.scrollHeight, 140)}px`;

    // A growing textarea shrinks the message pane; keep the latest bubble in view.
    if (wasAtBottom) {
        scrollToLatestRow();
    }

    if (! state.activeChannel) return;

    const now = Date.now();
    if (now - state.lastWhisperSent < 800) return;
    state.lastWhisperSent = now;

    // Client event: browser → Reverb → other browsers. Laravel never sees it.
    window.Echo.private(state.activeChannel).whisper('typing', { from: state.me.id });
});

/* ================================================================
 * Group creation
 * ================================================================ */

function renderGroupMemberChoices(users) {
    const box = $('group-members');
    box.innerHTML = '';

    if (! users.length) {
        box.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:12.5px">No users found.</div>';
        return;
    }

    users.forEach((user) => {
        const row = document.createElement('div');
        row.className = 'member-row';
        const selected = state.selectedMembers.has(user.id);
        row.innerHTML = `
            ${avatarWithNote(user.avatar, user.note, 'sm', user.name)}
            <span class="who">${esc(user.name)}<small>@${esc(user.username)}</small></span>
            <button type="button" class="btn ${selected ? 'primary' : ''}" data-id="${user.id}">${selected ? '✓ Added' : 'Add'}</button>`;
        row.querySelector('button').addEventListener('click', (event) => {
            if (state.selectedMembers.has(user.id)) {
                state.selectedMembers.delete(user.id);
                event.target.textContent = 'Add';
                event.target.classList.remove('primary');
            } else {
                state.selectedMembers.add(user.id);
                event.target.textContent = '✓ Added';
                event.target.classList.add('primary');
            }
        });
        box.appendChild(row);
    });
}

async function searchGroupMembers(query) {
    try {
        renderGroupMemberChoices(await api(`/api/users?q=${encodeURIComponent(query)}`));
    } catch (error) {
        toast(error.message);
    }
}

$('new-group-btn').addEventListener('click', () => {
    $('group-form').reset();
    state.selectedMembers.clear();
    $('group-modal').classList.add('show');
    searchGroupMembers('');
    $('group-name').focus();
});

$('group-member-search').addEventListener('input', debounce((event) => {
    searchGroupMembers(event.target.value.trim());
}, 250));

$('group-form').addEventListener('submit', async (event) => {
    event.preventDefault();

    if (! state.selectedMembers.size) {
        toast('Select at least one member.');
        return;
    }

    const avatar = $('group-avatar').files[0];
    if (avatar && avatar.size > 10 * 1024 * 1024) {
        toast('That picture is larger than 10 MB. Please choose a smaller image.');
        return;
    }

    const data = new FormData();
    data.set('name', $('group-name').value.trim());
    if (avatar) data.set('avatar', avatar);
    state.selectedMembers.forEach((id) => data.append('member_ids[]', id));

    try {
        const group = await api('/api/groups', { method: 'POST', body: data });
        $('group-modal').classList.remove('show');
        await loadConversations();
        await openConversation(group.conversation_id);
        toast('Group created.');
    } catch (error) {
        toast(error.message);
    }
});

/* ================================================================
 * Group settings: add/remove members, leave, delete
 * ================================================================ */

$('group-info-btn').addEventListener('click', () => {
    if (state.active?.group_id) {
        $('settings-modal').classList.add('show');
        loadGroupSettings(state.active.group_id);
    }
});

async function loadGroupSettings(groupId) {
    state.settingsGroupId = groupId;
    try {
        const group = await api(`/api/groups/${groupId}`);
        state.activeGroup = group;
        $('settings-title').textContent = group.name;
        $('leave-group-btn').hidden = group.creator_id === state.me.id;
        $('delete-group-btn').hidden = ! group.is_admin;

        $('settings-members').innerHTML = group.members.map((member) => `
            <div class="member-row">
                <img class="avatar sm" src="${esc(member.avatar)}" alt="${esc(member.name)}'s avatar" data-name="${esc(member.name)}">
                <span class="who">${esc(member.name)}<small>@${esc(member.username)} · ${esc(member.role)}</small></span>
                ${group.is_admin && member.id !== group.creator_id
                    ? `<button class="btn danger" type="button" data-remove="${member.id}">Remove</button>` : ''}
            </div>`).join('');

        $('settings-members').querySelectorAll('[data-remove]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await api(`/api/groups/${groupId}/members/${button.dataset.remove}`, { method: 'DELETE' });
                    loadGroupSettings(groupId);
                    loadConversations();
                } catch (error) {
                    toast(error.message);
                }
            });
        });
    } catch (error) {
        toast(error.message);
    }
}

$('add-member-search').addEventListener('input', debounce(async (event) => {
    if (! state.settingsGroupId) return;
    const query = event.target.value.trim();
    try {
        const users = await api(`/api/users?q=${encodeURIComponent(query)}`);
        renderUserResults(users, $('add-member-results'), async (user) => {
            try {
                await api(`/api/groups/${state.settingsGroupId}/members`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: user.id }),
                });
                $('add-member-search').value = '';
                $('add-member-results').innerHTML = '';
                loadGroupSettings(state.settingsGroupId);
                loadConversations();
            } catch (error) {
                toast(error.message);
            }
        });
    } catch (error) {
        toast(error.message);
    }
}, 250));

$('leave-group-btn').addEventListener('click', async () => {
    if (! state.activeGroup || ! confirm('Leave this group?')) return;
    try {
        await api(`/api/groups/${state.activeGroup.id}/leave`, { method: 'POST' });
        const conversationId = state.active?.id;
        $('settings-modal').classList.remove('show');
        if (conversationId) handleAccessRevoked(conversationId);
        loadConversations();
    } catch (error) {
        toast(error.message);
    }
});

$('delete-group-btn').addEventListener('click', async () => {
    if (! state.activeGroup || ! confirm('Delete this group for everyone?')) return;
    try {
        await api(`/api/groups/${state.activeGroup.id}`, { method: 'DELETE' });
        const conversationId = state.active?.id;
        $('settings-modal').classList.remove('show');
        if (conversationId) handleAccessRevoked(conversationId);
        loadConversations();
    } catch (error) {
        toast(error.message);
    }
});

document.querySelectorAll('[data-close]').forEach((button) => {
    button.addEventListener('click', () => $(button.dataset.close).classList.remove('show'));
});

document.querySelectorAll('.backdrop').forEach((backdrop) => {
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) backdrop.classList.remove('show');
    });
});

/* ================================================================
 * Sidebar / right-panel navigation
 * ================================================================ */

function openPeoplePanel() {
    const app = $('app');
    app.classList.remove('show-chat');
    if (window.matchMedia('(max-width: 1100px)').matches) {
        app.classList.add('people-visible');
    } else {
        app.classList.remove('people-hidden');
    }
}

function closePeoplePanel() {
    const app = $('app');
    app.classList.remove('people-visible');
    if (window.matchMedia('(max-width: 1100px)').matches) return;
    app.classList.add('people-hidden');
}

document.querySelectorAll('.people-toggle').forEach((button) => {
    button.addEventListener('click', () => {
        const app = $('app');
        const isOpen = window.matchMedia('(max-width: 1100px)').matches
            ? app.classList.contains('people-visible')
            : ! app.classList.contains('people-hidden');
        if (isOpen) closePeoplePanel();
        else openPeoplePanel();
    });
});

$('right-panel-close').addEventListener('click', closePeoplePanel);

// Opening a chat replaces the people panel on small screens.
function showChatView() {
    $('app').classList.remove('people-visible');
    $('app').classList.add('show-chat');
}

function activateRightTab(name) {
    const friends = name === 'friends';
    ['friends', 'requests'].forEach((tab) => {
        const selected = tab === name;
        $(`tab-${tab}`).classList.toggle('active', selected);
        $(`tab-${tab}`).setAttribute('aria-selected', String(selected));
    });
    $('panel-friends').hidden = ! friends;
    $('panel-requests').hidden = friends;
}

$('tab-friends').addEventListener('click', () => activateRightTab('friends'));
$('tab-requests').addEventListener('click', () => activateRightTab('requests'));

function updateRequestsBadge() {
    const friendCount = parseInt($('friend-incoming-count').textContent, 10) || 0;
    const messageCount = parseInt($('message-request-count').textContent, 10) || 0;
    const total = friendCount + messageCount;
    const badge = $('right-requests-badge');
    badge.textContent = total ? (total > 99 ? '99+' : String(total)) : '';
    badge.hidden = ! total;
}

/* ================================================================
 * Notes (24-hour, friends only)
 * ================================================================ */

async function loadNotes() {
    try {
        const page = await api('/api/notes');
        state.notes = page.data ?? [];
        renderNotes();
        renderMyNoteBubble();
    } catch (error) {
        toast(error.message);
    }
}

function renderMyNoteBubble() {
    const mine = state.notes.find((note) => note.is_mine);
    const bubble = $('me-note-bubble');
    const label = mine?.content ?? '+ Note';
    bubble.textContent = label;
    bubble.title = label;

    const trigger = $('me-note-avatar');
    trigger.title = mine ? 'Edit your note' : 'Leave a note';
    trigger.setAttribute('aria-label', trigger.title);
}

function renderNotes() {
    const box = $('notes-list');
    const mine = state.notes.find((note) => note.is_mine);

    const cards = [`<div class="note-card add" data-add="1" title="Add a note">
        <div class="note-cloud"><span class="plus">＋</span><span class="note-text">${mine ? 'Edit note' : 'Add note'}</span></div>
    </div>`];

    state.notes.filter((note) => ! note.is_mine).forEach((note) => {
        cards.push(`<div class="note-card" data-note="${note.id}" title="Open chat with ${esc(note.user.name)}">
            <div class="note-cloud">
                <img class="avatar" src="${esc(note.user.avatar)}" alt="${esc(note.user.name)}'s avatar" data-name="${esc(note.user.name)}">
                <span class="note-text">${esc(note.content)}</span>
            </div>
        </div>`);
    });

    box.innerHTML = cards.join('');
}

function openNoteEditor(note = null) {
    state.activeNote = note;
    $('note-modal-title').textContent = note ? 'Edit your note' : 'Add a note';
    $('note-content').value = note?.content ?? '';
    $('note-content').readOnly = false;
    $('note-save-btn').hidden = false;
    $('note-delete-btn').hidden = ! note;
    $('note-author').hidden = true;
    $('note-modal').classList.add('show');
    $('note-content').focus();
}

function openNoteViewer(note) {
    state.activeNote = null;
    $('note-modal-title').textContent = `${note.user.name}'s note`;
    $('note-content').value = note.content;
    $('note-content').readOnly = true;
    $('note-delete-btn').hidden = true;
    $('note-save-btn').hidden = true;
    $('note-author').hidden = false;
    $('note-author').innerHTML = `
        <img class="avatar sm" src="${esc(note.user.avatar)}" alt="${esc(note.user.name)}'s avatar" data-name="${esc(note.user.name)}">
        <span class="who">${esc(note.user.name)}<small>@${esc(note.user.username)} · ${esc(note.time ?? '')}</small></span>`;
    $('note-modal').classList.add('show');
}

$('add-note-btn').addEventListener('click', () => {
    openNoteEditor(state.notes.find((note) => note.is_mine) ?? null);
});

$('me-note-avatar').addEventListener('click', () => {
    openNoteEditor(state.notes.find((note) => note.is_mine) ?? null);
});

$('notes-list').addEventListener('click', async (event) => {
    const card = event.target.closest('.note-card');
    if (! card) return;

    if (card.dataset.add) {
        openNoteEditor(state.notes.find((note) => note.is_mine) ?? null);
        return;
    }

    const note = state.notes.find((n) => n.id === Number(card.dataset.note));
    if (! note) return;
    if (note.is_mine) {
        openNoteEditor(note);
    } else {
        // A friend's note is a shortcut into their chat.
        await openChatWithFriend(note.user.id);
    }
});

$('note-modal').addEventListener('click', (event) => {
    if (event.target === $('note-modal')) {
        $('note-content').readOnly = false;
        $('note-save-btn').hidden = false;
    }
});

$('note-save-btn').addEventListener('click', async () => {
    const content = $('note-content').value.trim();
    if (! content) {
        toast('Write something first.');
        return;
    }

    try {
        if (state.activeNote) {
            await api(`/api/notes/${state.activeNote.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
            });
        } else {
            await api('/api/notes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
            });
        }
        $('note-modal').classList.remove('show');
        $('note-content').readOnly = false;
        $('note-save-btn').hidden = false;
        toast('Note saved — it disappears after 24 hours.');
        loadNotes();
    } catch (error) {
        toast(error.message);
    }
});

$('note-delete-btn').addEventListener('click', async () => {
    if (! state.activeNote || ! confirm('Delete this note?')) return;
    try {
        await api(`/api/notes/${state.activeNote.id}`, { method: 'DELETE' });
        $('note-modal').classList.remove('show');
        toast('Note deleted.');
        loadNotes();
    } catch (error) {
        toast(error.message);
    }
});

/* ================================================================
 * Friends
 * ================================================================ */

async function openChatWithFriend(friendId) {
    try {
        const result = await api(`/api/conversations/direct/${friendId}`, { method: 'POST' });
        await loadConversations();
        await openConversation(result.id);
    } catch (error) {
        toast(error.message);
    }
}

async function loadFriends() {
    try {
        const data = await api('/api/friends');
        state.friends = data.friends?.data ?? [];
        state.friends.forEach((friend) => {
            if ('last_active_at' in friend || 'show_active_status' in friend) {
                state.presence.set(friend.id, { id: friend.id, ...friend });
            }
        });
        const incomingCount = data.counts?.incoming ?? 0;
        $('friends-count').textContent = data.counts?.friends ?? '';
        $('friend-incoming-count').textContent = incomingCount;
        updateRequestsBadge();

        const list = $('friend-list');
        list.innerHTML = state.friends.length ? state.friends.map((friend) => {
            const presence = presenceInfo(friend.id);
            return `
            <li class="conv-item" data-friend="${friend.id}" title="Open chat with ${esc(friend.name)}">
                ${avatarWithNote(friend.avatar, friend.note, '', friend.name)}
                <div class="body">
                    <div class="conv-row">
                        <span class="conv-name">${esc(friend.name)}</span>
                        <span class="conv-presence" data-friend-presence-for="${friend.id}"><span class="status-dot ${presence.online ? 'online' : ''}"></span>${presence.online ? 'Online' : 'Offline'}</span>
                    </div>
                    <div class="conv-bottom">
                        <span class="conv-preview">@${esc(friend.username)}${friend.status ? ' · ' + esc(friend.status) : ''}</span>
                        <span class="action-row">
                            <button class="row-icon-btn" data-message="${friend.id}" type="button" title="Chat" aria-label="Chat with ${esc(friend.name)}">${ICON_CHAT}</button>
                            <button class="row-icon-btn danger" data-unfriend="${friend.id}" type="button" title="Remove friend" aria-label="Remove ${esc(friend.name)}">${ICON_TRASH}</button>
                        </span>
                    </div>
                </div>
            </li>`;
        }).join('') : '<li class="empty-list">No friends yet. Open the Requests tab to send one.</li>';

        const incoming = data.incoming?.data ?? [];
        $('friend-request-list').innerHTML = incoming.length ? incoming.map((item) => `
            <li class="conv-item">
                ${avatarWithNote(item.user.avatar, item.user.note, '', item.user.name)}
                <div class="body">
                    <div class="conv-row"><span class="conv-name">${esc(item.user.name)}</span>
                        <span class="conv-time">${esc(formatShortTime(item.created_at))}</span></div>
                    <div class="conv-bottom">
                        <span class="conv-preview">${item.note ? esc(item.note) : '@'.concat(esc(item.user.username))}</span>
                        <span class="action-row">
                            <button class="row-icon-btn" data-accept-friend="${item.id}" type="button" title="Accept" aria-label="Accept friend request">${ICON_CHECK}</button>
                            <button class="row-icon-btn danger" data-reject-friend="${item.id}" type="button" title="Reject" aria-label="Reject friend request">✕</button>
                        </span>
                    </div>
                </div>
            </li>`).join('') : '<li class="empty-list">No incoming requests.</li>';

        const outgoing = data.outgoing?.data ?? [];
        $('friend-sent-list').innerHTML = outgoing.length ? outgoing.map((item) => `
            <li class="conv-item">
                ${avatarWithNote(item.user.avatar, item.user.note, '', item.user.name)}
                <div class="body">
                    <div class="conv-row"><span class="conv-name">${esc(item.user.name)}</span></div>
                    <div class="conv-bottom">
                        <span class="conv-preview">Pending${item.note ? ' · ' + esc(item.note) : ''}</span>
                        <button class="row-icon-btn danger" data-cancel-friend="${item.id}" type="button" title="Cancel request" aria-label="Cancel friend request">${ICON_TRASH}</button>
                    </div>
                </div>
            </li>`).join('') : '<li class="empty-list">No sent requests.</li>';

        state.friendRequests = { incoming, outgoing };
    } catch (error) {
        toast(error.message);
    }
}

$('friend-list').addEventListener('click', async (event) => {
    const messageButton = event.target.closest('[data-message]');
    const removeButton = event.target.closest('[data-unfriend]');

    if (messageButton) {
        await openChatWithFriend(Number(messageButton.dataset.message));
        return;
    }

    if (removeButton && confirm('Remove this friend?')) {
        try {
            await api(`/api/friends/user/${removeButton.dataset.unfriend}`, { method: 'DELETE' });
            toast('Friend removed.');
            loadFriends();
            loadNotes();
        } catch (error) {
            toast(error.message);
        }
        return;
    }

    // Clicking the row (including its note cloud) opens/starts the chat.
    const row = event.target.closest('[data-friend]');
    if (row) await openChatWithFriend(Number(row.dataset.friend));
});

$('friend-request-list').addEventListener('click', async (event) => {
    const accept = event.target.closest('[data-accept-friend]');
    const reject = event.target.closest('[data-reject-friend]');
    if (! accept && ! reject) return;

    const id = accept?.dataset.acceptFriend ?? reject?.dataset.rejectFriend;
    const action = accept ? 'accept' : 'reject';
    try {
        await api(`/api/friends/${id}/${action}`, { method: 'POST' });
        toast(accept ? 'Friend request accepted.' : 'Friend request rejected.');
        loadFriends();
        loadNotes();
    } catch (error) {
        toast(error.message);
    }
});

$('friend-sent-list').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-cancel-friend]');
    if (! button) return;
    try {
        await api(`/api/friends/${button.dataset.cancelFriend}`, { method: 'DELETE' });
        toast('Friend request cancelled.');
        loadFriends();
    } catch (error) {
        toast(error.message);
    }
});

function clearFindFriends() {
    $('friend-search').value = '';
    $('friend-request-note').value = '';
    $('friend-results').innerHTML = '';
}

$('find-friends-btn').addEventListener('click', () => {
    clearFindFriends();
    $('find-friends-modal').classList.add('show');
    $('friend-search').focus();
});

$('find-friends-modal').addEventListener('click', (event) => {
    if (event.target === $('find-friends-modal')) clearFindFriends();
});

$('friend-search').addEventListener('input', debounce(async (event) => {
    const query = event.target.value.trim();
    try {
        // scope=find-friends applies all exclusions on the backend: me,
        // existing friends, existing chats and pending requests either way.
        const users = await api(`/api/users?scope=find-friends&q=${encodeURIComponent(query)}`);
        renderUserResults(users, $('friend-results'), async (user) => {
            try {
                const note = $('friend-request-note').value.trim();
                const result = await api('/api/friends', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: user.id, note }),
                });
                clearFindFriends();
                $('find-friends-modal').classList.remove('show');
                toast(result.status === 'accepted' ? 'You are now friends.' : 'Friend request sent.');
                loadFriends();
            } catch (error) {
                toast(error.message);
            }
        });
    } catch (error) {
        toast(error.message);
    }
}, 250));

/* ================================================================
 * Message requests
 * ================================================================ */

async function loadMessageRequests() {
    try {
        const data = await api('/api/message-requests');
        state.messageRequests = data;
        const total = data.counts?.incoming ?? 0;
        const label = total > 99 ? '99+' : String(total);
        $('message-request-count').textContent = total ? `${total} new` : '';
        $('request-badge').hidden = ! total;
        $('request-badge').textContent = label;
        updateRequestsBadge();

        $('message-request-list').innerHTML = data.incoming?.data?.length ? data.incoming.data.map((item) => `
            <li class="conv-item" data-request="${item.id}">
                ${avatarWithNote(item.user.avatar, item.user.note, '', item.user.name)}
                <div class="body">
                    <div class="conv-row"><span class="conv-name">${esc(item.user.name)}</span>
                        <span class="conv-time">${esc(formatShortTime(item.created_at))}</span></div>
                    <div class="conv-bottom"><span class="conv-preview">${esc(item.preview)}</span></div>
                </div>
            </li>`).join('') : '<li class="empty-list">No message requests.</li>';

        $('message-sent-list').innerHTML = data.sent?.data?.length ? data.sent.data.map((item) => `
            <li class="conv-item" data-request="${item.id}">
                ${avatarWithNote(item.user.avatar, item.user.note, '', item.user.name)}
                <div class="body">
                    <div class="conv-row"><span class="conv-name">${esc(item.user.name)}</span></div>
                    <div class="conv-bottom"><span class="conv-preview">${esc(item.preview)}</span></div>
                </div>
            </li>`).join('') : '<li class="empty-list">No sent requests.</li>';
    } catch (error) {
        toast(error.message);
    }
}

function openRequestComposer(user) {
    state.currentRequest = { mode: 'new', user };
    $('request-details').innerHTML = `
        <div class="member-row">
            ${avatarWithNote(user.avatar, user.note, '', user.name)}
            <span class="who">${esc(user.name)}<small>@${esc(user.username)}</small></span>
        </div>
        <p class="muted">You are not friends yet. Send a short message request — they can accept it to start a normal chat.</p>`;
    $('request-messages').innerHTML = '';
    $('request-body').hidden = false;
    $('request-body').value = '';
    $('request-send-btn').hidden = false;
    $('request-accept-btn').hidden = true;
    $('request-delete-btn').hidden = true;
    $('request-cancel-btn').hidden = true;
    $('request-modal').classList.add('show');
    $('request-body').focus();
}

async function openRequestPreview(id) {
    try {
        const request = await api(`/api/message-requests/${id}`);
        const outgoing = request.direction === 'sent';
        state.currentRequest = { mode: 'view', id, incoming: ! outgoing };

        $('request-details').innerHTML = `
            <div class="member-row">
                ${avatarWithNote(request.user.avatar, request.user.note, '', request.user.name)}
                <span class="who">${esc(request.user.name)}
                    <small>@${esc(request.user.username)}${request.user.bio ? ' · ' + esc(request.user.bio) : ''}</small></span>
            </div>
            <p class="muted">${esc(formatShortTime(request.created_at))} · ${esc(request.status)}</p>`;
        $('request-messages').innerHTML = (request.messages ?? []).map((message) => `
            <div class="bubble">${esc(message.body || (message.attachments?.length ? '📎 Attachment' : ''))}</div>`).join('');
        $('request-body').hidden = true;
        $('request-send-btn').hidden = true;
        $('request-accept-btn').hidden = outgoing;
        $('request-delete-btn').hidden = outgoing;
        $('request-cancel-btn').hidden = ! outgoing;
        $('request-modal').classList.add('show');
    } catch (error) {
        toast(error.message);
    }
}

['message-request-list', 'message-sent-list'].forEach((listId) => {
    $(listId).addEventListener('click', (event) => {
        const item = event.target.closest('[data-request]');
        if (item) openRequestPreview(Number(item.dataset.request));
    });
});

$('request-send-btn').addEventListener('click', async () => {
    const body = $('request-body').value.trim();
    if (! body || ! state.currentRequest?.user) {
        toast('Write a short introduction first.');
        return;
    }
    try {
        await api('/api/message-requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: state.currentRequest.user.id, body }),
        });
        $('request-modal').classList.remove('show');
        toast('Message request sent.');
        loadMessageRequests();
    } catch (error) {
        toast(error.message);
    }
});

$('request-accept-btn').addEventListener('click', async () => {
    if (! state.currentRequest?.id) return;
    try {
        const result = await api(`/api/message-requests/${state.currentRequest.id}/accept`, { method: 'POST' });
        $('request-modal').classList.remove('show');
        toast('Request accepted — you are now friends.');
        await Promise.all([loadConversations(), loadFriends(), loadNotes(), loadMessageRequests()]);
        await openConversation(result.conversation_id);
    } catch (error) {
        toast(error.message);
    }
});

$('request-delete-btn').addEventListener('click', async () => {
    if (! state.currentRequest?.id || ! confirm('Delete this message request?')) return;
    try {
        await api(`/api/message-requests/${state.currentRequest.id}/delete`, { method: 'POST' });
        $('request-modal').classList.remove('show');
        toast('Message request deleted.');
        loadMessageRequests();
    } catch (error) {
        toast(error.message);
    }
});

$('request-cancel-btn').addEventListener('click', async () => {
    if (! state.currentRequest?.id) return;
    try {
        await api(`/api/message-requests/${state.currentRequest.id}`, { method: 'DELETE' });
        $('request-modal').classList.remove('show');
        toast('Message request cancelled.');
        loadMessageRequests();
    } catch (error) {
        toast(error.message);
    }
});

/* ================================================================
 * Notifications
 * ================================================================ */

function notificationText(event) {
    const data = event.data ?? {};
    const name = data.name ?? 'Someone';
    switch (event.type) {
        case 'friend_request_received': return `${name} sent you a friend request`;
        case 'friend_request_accepted': return `${name} accepted your friend request`;
        case 'friend_request_rejected': return `${name} declined your friend request`;
        case 'friend_request_cancelled': return `${name} cancelled their friend request`;
        case 'message_request_received': return `${name} sent you a message request`;
        case 'message_request_accepted': return `${name} accepted your message request`;
        case 'message_request_deleted': return `${name} deleted your message request`;
        case 'message_request_cancelled': return `${name} cancelled their message request`;
        case 'note_posted': return `${name} posted a note`;
        case 'note_updated': return `${name} updated their note`;
        case 'note_deleted': return `${name} removed their note`;
        default: return 'New notification';
    }
}

function updateNotificationBadge() {
    const badge = $('notification-badge');
    badge.hidden = state.notificationCount <= 0;
    badge.textContent = state.notificationCount > 99 ? '99+' : String(state.notificationCount);
}

async function loadNotifications() {
    try {
        const data = await api('/api/notifications');
        state.notificationCount = data.unread_count ?? 0;
        updateNotificationBadge();
        const notifications = data.data?.data ?? [];
        $('notifications-list').innerHTML = notifications.length ? notifications.map((item) => {
            const text = notificationText({ type: item.type, data: item.data });
            return `<div class="member-row ${item.read_at ? '' : 'unread'}" data-notification="${item.id}">
                <span class="who">${esc(text)}<small>${esc(formatShortTime(item.created_at))}</small></span>
                ${item.read_at ? '' : '<button class="mini-btn" data-read-notification="' + item.id + '" type="button">Read</button>'}
            </div>`;
        }).join('') : '<li class="empty-list">No notifications yet.</li>';
    } catch (error) {
        toast(error.message);
    }
}

$('notification-btn').addEventListener('click', () => {
    $('notifications-modal').classList.add('show');
    loadNotifications();
});

$('notifications-list').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-read-notification]');
    if (! button) return;
    try {
        await api(`/api/notifications/${button.dataset.readNotification}/read`, { method: 'POST' });
        loadNotifications();
    } catch (error) {
        toast(error.message);
    }
});

$('notifications-read-btn').addEventListener('click', async () => {
    try {
        await api('/api/notifications/read-all', { method: 'POST' });
        loadNotifications();
    } catch (error) {
        toast(error.message);
    }
});

/* ================================================================
 * Emoji picker + file attachments + lightbox
 * ================================================================ */

const EMOJIS = ['😀','😂','🥰','😍','😎','🤔','😭','😡','👍','👎','👏','🙏','💪','❤️','🔥','✨','🎉','🚀','💯','👀','😴','🤝','☕','🍕','📎','📷','✅','❌','😊','🥳','🤗','💡','🌙','⭐','💬','🕶️'];

$('emoji-picker').innerHTML = EMOJIS.map((emoji) => `<button type="button" data-emoji="${emoji}">${emoji}</button>`).join('');

$('emoji-btn').addEventListener('click', (event) => {
    event.stopPropagation();
    $('emoji-picker').hidden = ! $('emoji-picker').hidden;
});

$('emoji-picker').addEventListener('click', (event) => {
    const button = event.target.closest('[data-emoji]');
    if (! button) return;
    const input = $('message-input');
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + button.dataset.emoji + input.value.slice(end);
    input.focus();
    input.setSelectionRange(start + button.dataset.emoji.length, start + button.dataset.emoji.length);
});

document.addEventListener('click', (event) => {
    if (! event.target.closest('.composer-tools')) $('emoji-picker').hidden = true;
});

function renderAttachmentPreviews() {
    const box = $('attachment-previews');
    box.hidden = ! state.pendingAttachments.length;
    updateSendButton();
    box.innerHTML = state.pendingAttachments.map((file, index) => `
        <span class="attach-chip">📎 <span>${esc(file.name)}</span>
            <button type="button" data-remove-attachment="${index}" aria-label="Remove">✕</button>
        </span>`).join('');
}

$('attachment-previews').addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-attachment]');
    if (! button) return;
    state.pendingAttachments.splice(Number(button.dataset.removeAttachment), 1);
    renderAttachmentPreviews();
});

$('attach-btn').addEventListener('click', () => $('attachment-input').click());

$('attachment-input').addEventListener('change', (event) => {
    const chosen = [...(event.target.files ?? [])];
    const MAX = 20 * 1024 * 1024;
    let oversized = false;

    clearAttachmentError();

    for (const file of chosen) {
        if (file.size > MAX) {
            // The only inline file-size message: this file is never queued.
            oversized = true;
            continue;
        }
        if (state.pendingAttachments.length >= 5) {
            toast('You can attach up to 5 files at once.');
            break;
        }
        state.pendingAttachments.push(file);
    }

    if (oversized) showAttachmentError("File can't be larger than 20 MB");

    event.target.value = '';
    renderAttachmentPreviews();
});

$('messages').addEventListener('click', async (event) => {
    const unsendButton = event.target.closest('[data-unsend-id]');
    if (unsendButton) {
        const id = Number(unsendButton.dataset.unsendId);
        const confirmed = window.confirm(
            'Unsend this message? If it has already been seen, it will only be removed from your view.',
        );
        if (! confirmed) return;

        unsendButton.disabled = true;
        try {
            const result = await api(`/api/messages/${id}/unsend`, { method: 'POST' });
            applyUnsent(result);
            toast('Message unsent.');
        } catch (error) {
            unsendButton.disabled = false;
            toast(error.message);
        }
        return;
    }

    const image = event.target.closest('.msg-image');
    if (! image) return;
    $('lightbox-image').src = image.dataset.preview;
    $('lightbox-download').href = image.dataset.download;
    $('lightbox').classList.add('show');
});

/* ---------------- Go ---------------- */
updateSendButton();
loadPresence();
loadConversations();
loadFriends();
loadMessageRequests();
loadNotes();
loadNotifications();
