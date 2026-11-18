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

const state = {
    me: {
        id: Number(meta('user-id')),
        name: meta('user-name'),
        username: meta('user-username'),
        avatar: meta('user-avatar'),
    },
    online: new Map(),
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

function isOnline(userId) {
    return state.online.has(Number(userId));
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

window.Echo.join('online')
    .here((users) => {
        state.online = new Map(users.map((u) => [Number(u.id), u]));
        renderConversationList();
        updateChatHeader();
    })
    .joining((user) => {
        state.online.set(Number(user.id), user);
        renderConversationList();
        updateChatHeader();
    })
    .leaving((user) => {
        state.online.delete(Number(user.id));
        renderConversationList();
        updateChatHeader();
    });

window.Echo.private(`user.${state.me.id}`)
    .listen('.UnreadCountsUpdated', (event) => {
        state.conversations.forEach((c) => {
            c.unread = event.counts?.[c.id] ?? 0;
        });
        renderConversationList();
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
        renderConversationList();
    } catch (error) {
        toast(error.message);
    }
}

function renderConversationList() {
    const list = $('conversation-list');

    if (! state.conversations.length) {
        list.innerHTML = '<li class="empty-list">No conversations yet.<br>Search for someone to start chatting.</li>';
        return;
    }

    list.innerHTML = state.conversations.map((c) => {
        const preview = c.last_message
            ? `${c.last_message.sender_id === state.me.id ? 'You: ' : ''}${c.last_message.body}`
            : 'No messages yet';
        const dot = c.type === 'direct' && isOnline(c.peer_id)
            ? '<span class="status-dot online"></span>' : '';

        return `
            <li class="conv-item ${state.active?.id === c.id ? 'active' : ''}" data-id="${c.id}">
                <img class="avatar" src="${esc(c.avatar)}" alt="">
                <div class="body">
                    <div class="conv-row">
                        <span class="conv-name">${dot}${esc(c.name)}</span>
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
            <img class="avatar sm" src="${esc(user.avatar)}" alt="">
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
    const online = state.active.type === 'direct' && isOnline(state.active.peer_id);
    $('chat-subtitle').innerHTML = state.active.type === 'group'
        ? esc(state.active.subtitle ?? 'Group conversation')
        : `<span class="status-dot ${online ? 'online' : ''}"></span>${online ? 'Online' : 'Offline'}`;
    $('chat-avatar').src = state.active.avatar ?? '/images/default-avatar.svg';
    $('group-info-btn').hidden = state.active.type !== 'group';
}

function buildMessageRow(message) {
    const mine = message.sender_id === state.me.id;
    const row = document.createElement('div');
    row.className = `msg-row ${mine ? 'mine' : 'theirs'}`;
    row.dataset.id = message.id ?? '';
    row.innerHTML = `
        <img class="avatar sm" src="${esc(message.sender_avatar)}" alt="">
        <div>
            <div class="bubble">${esc(message.body)}</div>
            <span class="msg-meta">${esc(message.sender_name)} · ${esc(message.time ?? formatTime(message.created_at))}</span>
        </div>`;
    return row;
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
        $('app').classList.add('show-chat');
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
    $('app').classList.add('show-chat');
    $('messages').innerHTML = '';
    $('jump-latest').hidden = true;
    $('message-input').value = '';
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
        [...history.data].reverse().forEach((message) => appendMessage(message, false));
        scrollToLatestRow();
    } catch (error) {
        toast(error.message);
    }

    const channel = `conversation.${id}`;
    state.activeChannel = channel;

    window.Echo.private(channel)
        .listen('.MessageSent', (message) => {
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

$('composer').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (! state.active) return;

    const input = $('message-input');
    const body = input.value.trim();
    if (! body) return;

    input.value = '';
    input.style.height = 'auto';

    try {
        // Our own bubble arrives back over the WebSocket, just like everyone
        // else's — one render path for both sides.
        await api(`/api/conversations/${state.active.id}/messages`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ body }),
        });
    } catch (error) {
        input.value = body;
        toast(error.message);
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
            <img class="avatar sm" src="${esc(user.avatar)}" alt="">
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

    const data = new FormData();
    data.set('name', $('group-name').value.trim());
    if ($('group-avatar').files[0]) data.set('avatar', $('group-avatar').files[0]);
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
                <img class="avatar sm" src="${esc(member.avatar)}" alt="">
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

/* ---------------- Go ---------------- */
loadConversations();
