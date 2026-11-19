import './echo';
import './call-ui';

/*
 * Incognito Messenger — browser application.
 *
 * HTTP (fetch)  : search, history, sending, group management
 * WebSocket     : presence, live messages, unread badges, typing whispers
 */

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const $ = (id) => document.getElementById(id);
const csrf = () => meta('csrf-token');
const flag = (name, fallback = true) => {
    const value = meta(name);
    return value == null ? fallback : value === '1' || value === 'true';
};
const numMeta = (name, fallback = 0) => {
    const value = Number(meta(name));
    return Number.isFinite(value) ? value : fallback;
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
        messageSoundsVolume: Math.max(0, Math.min(100, numMeta('user-message-sounds-volume', 100))),
        desktopNotifications: flag('user-desktop-notifications', false),
        showActiveStatus: flag('user-show-active-status'),
        readReceipts: flag('user-read-receipts', true),
        themePreference: meta('user-theme-preference') || 'system',
    },
    conversations: [],
    conversationsLoaded: false,
    active: null,          // {id, type, group_id, peer_id, peer_username, name, avatar, subtitle, note, has_note}
    activeMessages: [],
    messagesLoading: false,
    activeChannel: null,
    activeGroup: null,
    cursor: null,
    loadingOlder: false,
    unseen: 0,
    peerTyping: false,
    typingHideTimer: null,
    lastWhisperSent: 0,
    selectedMembers: new Set(),
    settingsGroupId: null,
    friends: [],
    friendRequests: { incoming: [], outgoing: [], counts: {} },
    messageRequests: { incoming: [], sent: [], counts: {} },
    requestsUI: {
        subTab: 'received',
        loading: false,
        loadSeq: 0,
        receivedError: null,
        sentError: null,
        inFlight: new Set(),
    },
    notes: [],
    notesLoaded: false,
    activeNote: null,
    currentRequest: null,
    pendingAttachments: [],
    deleteTarget: null,
    notificationCount: 0,
    chatFilter: '',
    chatSearchQuery: '',
    sideTab: 'chats',
    muted: loadMutedSet(),
};

function loadMutedSet() {
    try {
        const raw = localStorage.getItem(`incognito-muted-${meta('user-id')}`);
        return new Set(raw ? JSON.parse(raw).map(Number) : []);
    } catch {
        return new Set();
    }
}

function persistMutedSet() {
    try {
        localStorage.setItem(`incognito-muted-${state.me.id}`, JSON.stringify([...state.muted]));
    } catch { /* ignore */ }
}

function isConvMuted(id) {
    return state.muted.has(Number(id));
}

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

/* ================================================================
 * Avatar fallback — never show broken/alt text, stable color per username
 * ================================================================ */
function avatarFallbackSrc(name, key) {
    const display = String(name ?? '').trim() || '?';
    const seed = String(key ?? display).trim() || display;
    const initial = (Array.from(display)[0] ?? '?').toUpperCase().replace(/[<>&"']/g, '');
    const palette = ['#0ea5e9', '#8b5cf6', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#f59e0b'];
    let hash = 0;
    for (const char of seed) hash = (hash * 31 + char.codePointAt(0)) >>> 0;
    const color = palette[hash % palette.length];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="${color}"/><text x="48" y="61" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="44" font-weight="600" fill="#fff">${initial}</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function applyAvatarFallback(img) {
    const current = img.getAttribute('src') || '';
    if (current.startsWith('data:image/svg+xml')) return;
    const rowName = img.closest('.conv-item, .msg-row, .member-row, .note-item, .static-results button, .me-card, .chat-head, .info-body, .request-details')
        ?.querySelector('.conv-name, .who strong, .note-name, strong')?.textContent?.trim();
    const name = img.dataset.name || rowName || state.me.name || '?';
    const key = img.dataset.username || img.dataset.key || name;
    img.dataset.name = name;
    img.alt = '';
    img.src = avatarFallbackSrc(name, key);
}

// Image error events do not bubble, so listen in the capture phase.
document.addEventListener('error', (event) => {
    const img = event.target;
    if (img instanceof HTMLImageElement && img.classList.contains('avatar')) {
        applyAvatarFallback(img);
    }
}, true);

function normalizeAvatarImages(root = document) {
    root.querySelectorAll('img.avatar').forEach((img) => {
        const src = img.getAttribute('src') || '';
        if (!src || src.includes('default-avatar.svg')) {
            applyAvatarFallback(img);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => normalizeAvatarImages(), { once: true });
} else {
    normalizeAvatarImages();
}

function isCustomAvatarSrc(src) {
    return !!(src && !String(src).includes('default-avatar.svg') && !String(src).startsWith('data:image/svg+xml'));
}

function avatarImg(src, name, username, extraClass = '', extraAttrs = '') {
    const cls = `avatar${extraClass ? ' ' + extraClass : ''}`;
    const displayName = name ?? '?';
    const key = username ?? name ?? '';
    const safeName = esc(displayName);
    const safeUser = esc(key);
    const effectiveSrc = isCustomAvatarSrc(src) ? src : avatarFallbackSrc(displayName, key);
    return `<img class="${cls}" src="${esc(effectiveSrc)}" alt="" data-name="${safeName}" data-username="${safeUser}" loading="lazy" decoding="async" ${extraAttrs}>`;
}

/* ================================================================
 * HTTP / UI helpers
 * ================================================================ */
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

    if (!response.ok) {
        let message = `Request failed (${response.status})`;
        try {
            const body = await response.json();
            const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : null;
            message = firstError ?? body.message ?? message;
        } catch { /* keep the generic message */ }
        throw new Error(message);
    }

    return response.status === 204 ? null : response.json();
}

function hideToast() {
    const el = $('toast');
    if (!el) return;
    el.classList.remove('show');
    const action = $('toast-action');
    if (action) {
        action.hidden = true;
        action.textContent = '';
        action.onclick = null;
    }
}

function toast(message, opts = {}) {
    const el = $('toast');
    const msgEl = $('toast-message');
    const actionEl = $('toast-action');
    if (!el || !msgEl) return;

    msgEl.textContent = message;
    if (actionEl) {
        if (opts.actionLabel && typeof opts.onAction === 'function') {
            actionEl.hidden = false;
            actionEl.textContent = opts.actionLabel;
            actionEl.onclick = async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const fn = opts.onAction;
                hideToast();
                try { await fn(); } catch (err) { console.error(err); }
            };
        } else {
            actionEl.hidden = true;
            actionEl.textContent = '';
            actionEl.onclick = null;
        }
    }

    el.classList.add('show');
    clearTimeout(toast.timer);
    const duration = Number(opts.duration) > 0 ? Number(opts.duration) : (opts.actionLabel ? 5000 : 3200);
    toast.timer = setTimeout(() => hideToast(), duration);
}

function timeAgo(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    const weeks = Math.floor(days / 7);
    if (weeks < 5) return `${weeks}w ago`;
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatBadgeCount(n) {
    const num = Number(n || 0);
    if (!num) return '';
    return num > 9 ? '9+' : String(num);
}

function formatTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function formatShortTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const today = new Date();
    if (date.toDateString() === today.toDateString()) return formatTime(value);
    return date.toLocaleDateString([], { month: 'numeric', day: 'numeric' });
}

function dayLabel(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === today.toDateString()) return 'Today';
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return date.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
}

function sameDay(a, b) {
    const da = new Date(a);
    const db = new Date(b);
    return !Number.isNaN(da) && !Number.isNaN(db) && da.toDateString() === db.toDateString();
}

function relativeActive(value, now = Date.now()) {
    if (!value) return '';
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

    return '';
}

function usernameFromSubtitle(subtitle) {
    if (typeof subtitle === 'string' && subtitle.startsWith('@')) return subtitle.slice(1);
    return '';
}

/* ================================================================
 * Presence
 * ================================================================ */
function presenceInfo(userId) {
    const id = Number(userId);
    if (!id) return { online: false, label: '' };

    const member = state.online.get(id);
    const record = state.presence.get(id);
    const hidden = member?.show_active_status === false || record?.show_active_status === false;
    if (hidden) return { online: false, label: '' };

    const online = state.online.has(id) || record?.is_online === true;
    const lastActiveAt = record?.last_active_at ?? member?.last_active_at ?? null;
    const label = online ? 'Active now' : relativeActive(lastActiveAt);

    return { online, label };
}

function updatePresenceUI() {
    renderConversationList();
    if (state.active) updateChatHeader();
    renderFriends();
}

async function heartbeat() {
    try { await api('/api/presence/heartbeat', { method: 'POST' }); } catch { /* ignore */ }
}

async function loadPresence() {
    try {
        const users = await api('/api/presence');
        users.forEach((user) => state.presence.set(Number(user.id), user));
        updatePresenceUI();
    } catch { /* keep last known */ }
}

window.Echo.join('online')
    .here((users) => {
        state.online = new Map(users.map((u) => [Number(u.id), u]));
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
    if (!navigator.sendBeacon) return;
    try {
        navigator.sendBeacon('/api/presence/disconnect', new URLSearchParams({
            _token: csrf(),
            tab_id: window.incognitoCalls?.tabId || '',
        }));
    } catch { /* best-effort */ }
}

setInterval(() => {
    heartbeat();
    loadPresence();
}, 30000);

/* ================================================================
 * Sounds
 * ================================================================ */
const sounds = {};
try {
    for (const [kind, path] of Object.entries({
        received: '/sounds/received.wav',
        sent: '/sounds/sent.wav',
    })) {
        sounds[kind] = new Audio(path);
        sounds[kind].preload = 'auto';
    }
} catch { /* optional */ }

function playSound(kind) {
    if (!state.settings.messageSounds) return;
    if (kind === 'received' && state.active && isConvMuted(state.active.id)) return;
    const sound = sounds[kind];
    if (!sound) return;
    try {
        sound.volume = Math.max(0, Math.min(1, (state.settings.messageSoundsVolume ?? 100) / 100));
        sound.currentTime = 0;
        const promise = sound.play();
        if (promise?.catch) promise.catch(() => {});
    } catch { /* autoplay blocked */ }
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
    if (!state.settings.messageSounds) return false;
    if (message.type === 'call') return false;
    if (isConvMuted(message.conversation_id)) return false;
    if (message.sender_id === state.me.id || message.is_unsent) return false;
    const activeChatFocused = state.active?.id === message.conversation_id
        && document.visibilityState === 'visible'
        && document.hasFocus();
    return !activeChatFocused;
}

/* ================================================================
 * Icons / ticks
 * ================================================================ */
const ICON_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5 9 17.5 20 6.5"/></svg>';
const ICON_DOUBLE_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12.5 7 17.5 17.5 7"/><path d="M9 12.5 14 17.5 22 8.5"/></svg>';

function messageIsSeen(message) {
    return message.status === 'seen' && (message.seen_by ?? []).length > 0;
}

function statusTicksHtml(message) {
    if (message.type === 'call') return '';
    if (message.sender_id !== state.me.id || message.is_unsent) return '';
    const seen = messageIsSeen(message);
    const label = seen ? 'Seen' : 'Sent';
    return `<span class="status-ticks${seen ? ' seen' : ''}" data-status-ticks="${message.id}" role="img" aria-label="${label}" title="${label}">${seen ? ICON_DOUBLE_CHECK : ICON_CHECK}<span class="sr-only">${label}</span></span>`;
}

function setStatusTicks(row, seen) {
    const holder = row?.querySelector('[data-status-ticks]');
    if (!holder) return;
    const label = seen ? 'Seen' : 'Sent';
    holder.classList.toggle('seen', seen);
    holder.setAttribute('aria-label', label);
    holder.setAttribute('title', label);
    holder.innerHTML = `${seen ? ICON_DOUBLE_CHECK : ICON_CHECK}<span class="sr-only">${label}</span>`;
}

/* ================================================================
 * Scroll helpers
 * ================================================================ */
function isNearBottom(threshold = 80) {
    const box = $('messages');
    if (!box) return true;
    return box.scrollHeight - box.scrollTop - box.clientHeight <= threshold;
}

function updateJumpLatest() {
    const box = $('messages');
    const button = $('jump-latest');
    if (!box || !button) return;
    const scrollable = box.scrollHeight - box.clientHeight > 24;
    button.hidden = !scrollable || isNearBottom();
    button.textContent = state.unseen > 0
        ? `New messages (${state.unseen}) ↓`
        : 'Jump to latest ↓';
}

function scrollBottom(smooth = false) {
    const box = $('messages');
    if (!box) return;
    if (typeof box.scrollTo === 'function') {
        box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    } else {
        box.scrollTop = box.scrollHeight;
    }
    state.unseen = 0;
    updateJumpLatest();
}

function scrollToLatestRow(smooth = false) {
    const box = $('messages');
    if (!box) return;
    const rows = box.querySelectorAll('.msg-row');
    const last = rows[rows.length - 1];
    if (!last || last.getBoundingClientRect().height <= box.clientHeight - 24) {
        scrollBottom(smooth);
        return;
    }
    const top = last.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop;
    box.scrollTo({ top: Math.max(0, top - 8), behavior: smooth ? 'smooth' : 'auto' });
    state.unseen = 0;
    updateJumpLatest();
}

/* ================================================================
 * Skeletons / empty states
 * ================================================================ */
function renderChatListSkeleton() {
    const list = $('conversation-list');
    if (!list) return;
    list.innerHTML = [0, 1, 2, 3].map(() => `
        <li class="skeleton-chat">
            <span class="skeleton circle"></span>
            <div class="sk-lines">
                <span class="skeleton line w70"></span>
                <span class="skeleton line w50"></span>
            </div>
        </li>`).join('');
}

function renderNotesSkeleton() {
    const box = $('notes-list');
    if (!box) return;
    box.innerHTML = `<div class="skeleton-notes" aria-hidden="true">${[0,1,2,3].map(() => '<span class="skeleton circle"></span>').join('')}</div>`;
}

function renderMessageSkeleton() {
    const box = $('messages');
    if (!box) return;
    box.innerHTML = `
        <div class="msg-skeleton" aria-hidden="true">
            <div class="msg-skel-row"><span class="skeleton msg-skel-bubble w40"></span></div>
            <div class="msg-skel-row mine"><span class="skeleton msg-skel-bubble w55"></span></div>
            <div class="msg-skel-row"><span class="skeleton msg-skel-bubble w30"></span></div>
            <div class="msg-skel-row mine"><span class="skeleton msg-skel-bubble w40"></span></div>
        </div>`;
}

/* ================================================================
 * Sidebar tabs / menus
 * ================================================================ */
function activateSideTab(name) {
    state.sideTab = name;
    ['chats', 'requests', 'friends'].forEach((tab) => {
        const btn = $(`side-tab-${tab}`);
        const pane = $(`side-pane-${tab}`);
        if (!btn || !pane) return;
        const selected = tab === name;
        btn.classList.toggle('active', selected);
        btn.setAttribute('aria-selected', String(selected));
        pane.hidden = !selected;
    });
    if (name === 'requests') {
        activateRequestsSubTab(state.requestsUI.subTab || 'received', { fromTabOpen: true });
    }
}

function openFindFriendsModal() {
    $('find-friends-modal')?.classList.add('show');
    if ($('friend-search')) $('friend-search').value = '';
    if ($('friend-request-note')) $('friend-request-note').value = '';
    searchFindFriends('');
    $('friend-search')?.focus();
}

function closeDropdowns(except = null) {
    document.querySelectorAll('.dropdown-menu').forEach((menu) => {
        if (menu === except) return;
        menu.hidden = true;
        const btn = document.querySelector(`[aria-controls="${menu.id}"], [aria-expanded][aria-haspopup]`);
    });
    document.querySelectorAll('[aria-expanded="true"]').forEach((btn) => {
        const menuId = btn.id === 'me-menu-btn' ? 'me-menu' : btn.id === 'sidebar-add-btn' ? 'sidebar-add-menu' : null;
        if (menuId && $(menuId) !== except) btn.setAttribute('aria-expanded', 'false');
    });
}

function toggleMenu(btnId, menuId) {
    const btn = $(btnId);
    const menu = $(menuId);
    if (!btn || !menu) return;
    const open = menu.hidden;
    closeDropdowns(open ? menu : null);
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
    if (open) menu.hidden = false;
}

function requestsUnseenTotal() {
    const f = Number(state.friendRequests.counts?.unseen_incoming ?? (state.friendRequests.incoming || []).filter((r) => r.unseen).length);
    const m = Number(state.messageRequests.counts?.unseen_incoming ?? (state.messageRequests.incoming || []).filter((r) => r.unseen).length);
    return Math.max(0, f) + Math.max(0, m);
}

function setRequestsBadges(unseenOverride = null) {
    const unseen = unseenOverride == null ? requestsUnseenTotal() : Math.max(0, Number(unseenOverride) || 0);
    const label = formatBadgeCount(unseen);
    const side = $('side-requests-badge');
    if (side) { side.textContent = label; side.hidden = !unseen; }
    const recv = $('requests-received-badge');
    if (recv) { recv.textContent = label; recv.hidden = !unseen; }
}

function updateSideRequestsBadge() {
    setRequestsBadges();
}

function reqInFlightKey(kind, id, action) {
    return `${kind}:${id}:${action}`;
}

function setReqInFlight(kind, id, action, on) {
    const key = reqInFlightKey(kind, id, action);
    if (on) state.requestsUI.inFlight.add(key); else state.requestsUI.inFlight.delete(key);
}

function isReqInFlight(kind, id, action = null) {
    if (action) return state.requestsUI.inFlight.has(reqInFlightKey(kind, id, action));
    const prefix = `${kind}:${id}:`;
    for (const k of state.requestsUI.inFlight) if (k.startsWith(prefix)) return true;
    return false;
}

function normalizeFriendReq(r, direction) {
    return { ...r, direction, kind: 'friend', created_ts: Date.parse(r.created_at || '') || 0 };
}
function normalizeMessageReq(r, direction) {
    return { ...r, direction, kind: 'message', created_ts: Date.parse(r.created_at || '') || 0 };
}

function allReceivedRequests() {
    const f = (state.friendRequests.incoming || []).map((r) => normalizeFriendReq(r, 'incoming'));
    const m = (state.messageRequests.incoming || []).map((r) => normalizeMessageReq(r, 'incoming'));
    const map = new Map();
    [...f, ...m].forEach((r) => map.set(`${r.kind}:${r.id}`, r));
    return [...map.values()].sort((a, b) => b.created_ts - a.created_ts);
}

function allSentRequests() {
    const f = (state.friendRequests.outgoing || []).map((r) => normalizeFriendReq(r, 'outgoing'));
    const m = (state.messageRequests.sent || []).map((r) => normalizeMessageReq(r, 'outgoing'));
    const map = new Map();
    [...f, ...m].forEach((r) => map.set(`${r.kind}:${r.id}`, r));
    return [...map.values()].sort((a, b) => b.created_ts - a.created_ts);
}

function markLocalRequestsSeen() {
    (state.friendRequests.incoming || []).forEach((r) => { r.unseen = false; r.seen_at = r.seen_at || new Date().toISOString(); });
    (state.messageRequests.incoming || []).forEach((r) => { r.unseen = false; r.seen_at = r.seen_at || new Date().toISOString(); });
    if (state.friendRequests.counts) state.friendRequests.counts.unseen_incoming = 0;
    if (state.messageRequests.counts) state.messageRequests.counts.unseen_incoming = 0;
    setRequestsBadges(0);
}

async function markRequestsSeen() {
    if (requestsUnseenTotal() <= 0) return;
    markLocalRequestsSeen();
    try {
        await api('/api/requests/seen', { method: 'POST' });
    } catch (error) {
        // refresh to stay in sync if the optimistic clear was wrong
        loadRequests({ showLoading: false });
        console.warn('mark requests seen failed', error);
    }
}

function requestsSkeletonHtml() {
    return [0, 1, 2].map(() => `
        <div class="req-skel" aria-hidden="true">
            <span class="skeleton sk-av"></span>
            <span class="sk-mid"><span class="skeleton line w60"></span><span class="skeleton line w40"></span></span>
            <span class="skeleton sk-act"></span>
        </div>`).join('');
}

function setRequestsPanelState(which, { loading = false, error = null, empty = false } = {}) {
    const l = $(`requests-${which}-loading`);
    const e = $(`requests-${which}-error`);
    const em = $(`requests-${which}-empty`);
    const c = $(`requests-${which}-content`);
    if (l) { l.hidden = !loading; if (loading) l.innerHTML = requestsSkeletonHtml(); }
    if (e) e.hidden = !error || loading;
    if (em) em.hidden = !empty || loading || !!error;
    if (c) c.hidden = loading || !!error || empty;
}

function escapeAttr(value) {
    return esc(value).replace(/"/g, '&quot;');
}

function requestCardHtml(item) {
    const u = item.user || {};
    const kind = item.kind;
    const id = item.id;
    const name = u.name || 'Unknown';
    const username = u.username ? `@${u.username}` : '';
    const when = timeAgo(item.created_at);
    const note = item.note ? String(item.note) : '';
    const preview = item.preview ? String(item.preview) : '';
    const busy = isReqInFlight(kind, id);
    const disabled = busy ? 'disabled aria-disabled="true"' : '';
    const unameKey = u.username || u.name || '';

    let bodyExtra = '';
    if (note) bodyExtra += `<div class="req-note">${esc(note)}</div>`;
    if (kind === 'message' && preview) bodyExtra += `<div class="req-preview">${esc(preview)}</div>`;

    let actions = '';
    if (item.direction === 'incoming' && kind === 'friend') {
        actions = `
            <div class="req-actions">
                <button class="req-icon-btn accept" type="button" data-req-action="friend-accept" data-kind="friend" data-id="${id}" title="Accept" aria-label="Accept friend request" ${disabled}>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                </button>
                <button class="req-icon-btn decline" type="button" data-req-action="friend-decline" data-kind="friend" data-id="${id}" title="Decline" aria-label="Decline friend request" ${disabled}>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>`;
    } else if (item.direction === 'incoming' && kind === 'message') {
        actions = `
            <div class="req-actions">
                <button class="mini-btn primary" type="button" data-req-action="message-accept" data-kind="message" data-id="${id}" ${disabled}>Accept</button>
                <button class="mini-btn danger" type="button" data-req-action="message-delete" data-kind="message" data-id="${id}" ${disabled}>Delete</button>
                <div class="req-menu-wrap">
                    <button class="req-icon-btn more" type="button" data-req-menu-toggle="${kind}:${id}" title="More actions" aria-label="More actions" aria-expanded="false" aria-haspopup="menu" ${disabled}>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div class="req-menu" role="menu" data-req-menu="${kind}:${id}" hidden>
                        <button class="req-menu-item" type="button" role="menuitem" data-req-action="message-block" data-kind="message" data-id="${id}">Block</button>
                        <button class="req-menu-item danger" type="button" role="menuitem" data-req-action="message-report" data-kind="message" data-id="${id}">Report</button>
                    </div>
                </div>
            </div>`;
    } else if (item.direction === 'outgoing') {
        const cancelAction = kind === 'friend' ? 'friend-cancel' : 'message-cancel';
        actions = `
            <div class="req-actions">
                <span class="req-chip">Pending</span>
                <button class="mini-btn" type="button" data-req-action="${cancelAction}" data-kind="${kind}" data-id="${id}" ${disabled}>Cancel</button>
            </div>`;
    }

    return `
        <li class="req-card" data-req-kind="${kind}" data-req-id="${id}" data-req-dir="${item.direction}">
            ${avatarImg(u.avatar, name, unameKey)}
            <div class="req-main">
                <div class="req-top">
                    <span class="req-name">${esc(name)}</span>
                    ${username ? `<span class="req-username">${esc(username)}</span>` : ''}
                    ${when ? `<span class="req-time">${esc(when)}</span>` : ''}
                </div>
                ${bodyExtra}
            </div>
            ${actions}
        </li>`;
}

function renderRequestGroup(label, items) {
    if (!items.length) return '';
    return `
        <section class="requests-group">
            <h3 class="requests-group-label">${esc(label)}</h3>
            <ul class="req-list">${items.map(requestCardHtml).join('')}</ul>
        </section>`;
}

function renderReceivedRequests() {
    const container = $('requests-received-content');
    if (!container) return;
    const err = state.requestsUI.receivedError;
    const loading = state.requestsUI.loading;
    const items = allReceivedRequests();
    if (err) { setRequestsPanelState('received', { error: err }); return; }
    if (loading && !items.length) { setRequestsPanelState('received', { loading: true }); return; }
    if (!items.length) { setRequestsPanelState('received', { empty: true }); container.innerHTML = ''; return; }
    const friends = items.filter((r) => r.kind === 'friend');
    const messages = items.filter((r) => r.kind === 'message');
    container.innerHTML = `${renderRequestGroup('Friend requests', friends)}${renderRequestGroup('Message requests', messages)}`;
    setRequestsPanelState('received', {});
}

function renderSentRequests() {
    const container = $('requests-sent-content');
    if (!container) return;
    const err = state.requestsUI.sentError;
    const loading = state.requestsUI.loading;
    const items = allSentRequests();
    if (err) { setRequestsPanelState('sent', { error: err }); return; }
    if (loading && !items.length) { setRequestsPanelState('sent', { loading: true }); return; }
    if (!items.length) { setRequestsPanelState('sent', { empty: true }); container.innerHTML = ''; return; }
    const friends = items.filter((r) => r.kind === 'friend');
    const messages = items.filter((r) => r.kind === 'message');
    container.innerHTML = `${renderRequestGroup('Friend requests', friends)}${renderRequestGroup('Message requests', messages)}`;
    setRequestsPanelState('sent', {});
}

function renderRequestsUI() {
    renderReceivedRequests();
    renderSentRequests();
    setRequestsBadges();
}

function activateRequestsSubTab(name, opts = {}) {
    state.requestsUI.subTab = name === 'sent' ? 'sent' : 'received';
    ['received', 'sent'].forEach((tab) => {
        const btn = $(`requests-subtab-${tab}`);
        const panel = $(`requests-panel-${tab}`);
        const selected = tab === state.requestsUI.subTab;
        if (btn) { btn.classList.toggle('active', selected); btn.setAttribute('aria-selected', String(selected)); }
        if (panel) panel.hidden = !selected;
    });
    renderRequestsUI();
    if ((opts.fromTabOpen || state.sideTab === 'requests') && state.requestsUI.subTab === 'received') {
        markRequestsSeen();
    }
}

/* ================================================================
 * Notes
 * ================================================================ */
async function loadNotes() {
    try {
        const page = await api('/api/notes');
        state.notes = Array.isArray(page) ? page : (page.data ?? []);
        state.notesLoaded = true;
        renderNotes();
    } catch (error) {
        state.notesLoaded = true;
        renderNotes();
        console.warn('notes load failed', error);
    }
}

function renderNotes() {
    const box = $('notes-list');
    if (!box) return;
    const mine = state.notes.find((n) => n.is_mine);
    const others = state.notes.filter((n) => !n.is_mine);

    let html = '';
    // "+ Add note" first (my note)
    const addLabel = mine ? esc(mine.content) : 'Add note';
    const addName = 'Your note';
    html += `
        <button class="note-item" type="button" data-note-action="add" title="${mine ? 'Edit your note' : 'Add a note'}" aria-label="${mine ? 'Edit your note' : 'Add a note'}">
            <span class="note-visual">
                ${mine ? `<span class="note-bubble">${esc(mine.content)}</span>` : ''}
                ${avatarImg(state.me.avatar, state.me.name, state.me.username, '', '')}
                ${!mine ? `<span class="note-plus" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>` : ''}
            </span>
            <span class="note-name">${mine ? 'You' : 'Add note'}</span>
        </button>`;

    others.forEach((note) => {
        const u = note.user || {};
        html += `
            <button class="note-item" type="button" data-note-id="${note.id}" data-note-user="${u.id ?? ''}" title="${esc(u.name ?? 'Note')}" aria-label="${esc(u.name ?? 'Note')}’s note">
                <span class="note-visual">
                    <span class="note-bubble">${esc(note.content)}</span>
                    ${avatarImg(u.avatar, u.name, u.username, '', '')}
                </span>
                <span class="note-name">${esc((u.name || '').split(' ')[0] || 'Friend')}</span>
            </button>`;
    });

    box.innerHTML = html;
}

function openNoteEditor(note = null) {
    state.activeNote = note;
    $('note-modal-title').textContent = note ? 'Edit your note' : 'Add a note';
    $('note-content').value = note?.content ?? '';
    $('note-content').readOnly = false;
    $('note-save-btn').hidden = false;
    $('note-delete-btn').hidden = !note;
    $('note-author').hidden = true;
    $('note-modal').classList.add('show');
    $('note-content').focus();
}

function openNoteViewer(note) {
    state.activeNote = null;
    const u = note.user || {};
    $('note-modal-title').textContent = `${u.name ?? 'Friend'}’s note`;
    $('note-content').value = note.content ?? '';
    $('note-content').readOnly = true;
    $('note-save-btn').hidden = true;
    $('note-delete-btn').hidden = true;
    $('note-author').hidden = false;
    $('note-author').innerHTML = `
        ${avatarImg(u.avatar, u.name, u.username, 'sm')}
        <span class="who">${esc(u.name ?? '')}<small>@${esc(u.username ?? '')} · ${esc(note.time ?? '')}</small></span>`;
    $('note-modal').classList.add('show');
}

async function saveNote() {
    const content = $('note-content').value.trim();
    if (!content) { toast('Write a short note first.'); return; }
    try {
        if (state.activeNote) {
            await api(`/api/notes/${state.activeNote.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
            });
            toast('Note updated.');
        } else {
            await api('/api/notes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
            });
            toast('Note added.');
        }
        $('note-modal').classList.remove('show');
        await Promise.all([loadNotes(), loadConversations()]);
    } catch (error) {
        toast(error.message);
    }
}

async function deleteNote() {
    if (!state.activeNote) return;
    try {
        await api(`/api/notes/${state.activeNote.id}`, { method: 'DELETE' });
        $('note-modal').classList.remove('show');
        toast('Note deleted.');
        await Promise.all([loadNotes(), loadConversations()]);
    } catch (error) {
        toast(error.message);
    }
}

/* ================================================================
 * Conversations
 * ================================================================ */
function peerKeyForConversation(c) {
    if (c?.type !== 'direct') return `group:${c?.group_id ?? c?.id}`;
    return c.peer_username || usernameFromSubtitle(c.subtitle) || c.peer_id || c.name;
}

async function loadConversations() {
    try {
        state.conversations = await api('/api/conversations');
        state.conversationsLoaded = true;

        // Normalize helper fields
        state.conversations.forEach((c) => {
            if (c.type === 'direct') {
                c.peer_username = c.peer_username || usernameFromSubtitle(c.subtitle);
            }
        });

        if (state.active) {
            const fresh = state.conversations.find((c) => c.id === state.active.id);
            if (fresh) {
                state.active = { ...state.active, ...fresh, peer_username: fresh.peer_username || peerKeyForConversation(fresh) };
                updateChatHeader();
            }
        }

        renderConversationList();
    } catch (error) {
        state.conversationsLoaded = true;
        renderConversationList();
        console.warn('conversations load failed', error);
    }
}

function applyUnreadCounts(counts) {
    if (!counts) return;
    state.conversations.forEach((conversation) => {
        conversation.unread = counts[conversation.id] ?? 0;
    });
    renderConversationList();
}

/* ================================================================
 * Seen receipts for live messages
 * ================================================================ */
const seenRequests = { inFlight: new Set(), queued: new Set() };

async function markConversationSeen(id) {
    const conversationId = Number(id);
    if (!conversationId) return;

    if (seenRequests.inFlight.has(conversationId)) {
        seenRequests.queued.add(conversationId);
        return;
    }

    seenRequests.inFlight.add(conversationId);
    try {
        const result = await api(`/api/conversations/${conversationId}/seen`, { method: 'POST' });
        if (result?.counts) applyUnreadCounts(result.counts);
    } catch {
        // Best-effort: the next focus/open will try again.
    } finally {
        seenRequests.inFlight.delete(conversationId);
        if (seenRequests.queued.delete(conversationId)) {
            markConversationSeen(conversationId);
        }
    }
}

async function markMessageSeen(id) {
    try {
        const result = await api(`/api/messages/${id}/seen`, { method: 'POST' });
        if (result?.unread_counts) applyUnreadCounts(result.unread_counts);
    } catch {
        // Best-effort; the batch seen endpoint on focus is the safety net.
    }
}

function maybeMarkActiveConversationSeen() {
    if (!state.active) return;
    if (document.visibilityState !== 'visible') return;
    markConversationSeen(state.active.id);
}

window.addEventListener('focus', maybeMarkActiveConversationSeen);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') maybeMarkActiveConversationSeen();
});

function conversationPreviewText(c) {
    const last = c.last_message;
    if (!last) return 'No messages yet';
    if (last.type === 'call') return last.preview || 'Audio call';
    if (last.is_unsent) return last.preview || 'Message unsent';
    const prefix = last.sender_id === state.me.id ? 'You: ' : '';
    const body = last.body || (last.attachments?.length ? `📎 ${last.attachments[0].original_filename}` : '');
    return `${prefix}${body}`;
}

function renderConversationList() {
    const list = $('conversation-list');
    if (!list) return;

    if (!state.conversationsLoaded) {
        renderChatListSkeleton();
        return;
    }

    const q = (state.chatFilter || '').trim().toLowerCase();
    let items = [...state.conversations];
    if (q) {
        items = items.filter((c) => {
            const hay = [c.name, c.subtitle, c.peer_username, conversationPreviewText(c)].join(' ').toLowerCase();
            return hay.includes(q);
        });
    }

    if (!items.length) {
        list.innerHTML = `<li class="empty-list">${state.conversations.length ? 'No chats match your search.' : 'No chats yet.<br>Use + to find friends or start a group.'}</li>`;
        return;
    }

    list.innerHTML = items.map((c) => {
        const preview = conversationPreviewText(c);
        const unread = Number(c.unread || 0);
        const presence = c.type === 'direct' ? presenceInfo(c.peer_id) : { online: false, label: '' };
        const usernameKey = peerKeyForConversation(c);
        const showNoteDot = c.type === 'direct' && (c.has_note || c.note);
        return `
            <li class="conv-item ${state.active?.id === c.id ? 'active' : ''}" data-id="${c.id}" role="button" tabindex="0">
                <span class="avatar-wrap">
                    ${showNoteDot ? '<span class="note-indicator" aria-hidden="true"></span>' : ''}
                    ${avatarImg(c.avatar, c.name, usernameKey)}
                    ${c.type === 'direct' && presence.online ? '<span class="online-dot" aria-hidden="true"></span>' : ''}
                </span>
                <div class="body">
                    <div class="conv-row">
                        <span class="conv-name">${esc(c.name)}</span>
                        <span class="conv-time">${esc(formatShortTime(c.last_at || c.last_message?.created_at))}</span>
                    </div>
                    <div class="conv-bottom">
                        <span class="conv-preview">${esc(preview)}</span>
                        <span class="unread-badge">${unread ? esc(unread) : ''}</span>
                    </div>
                </div>
            </li>`;
    }).join('');
}

function openConversationById(id) {
    if (!id) return;
    openConversation(Number(id));
}

/* ================================================================
 * Friends & Requests
 * ================================================================ */
function renderFriends() {
    const list = $('friend-list');
    if (!list) return;
    const count = $('friends-count');
    const friends = state.friends || [];
    if (count) count.textContent = friends.length ? String(friends.length) : '';
    if (!friends.length) {
        list.innerHTML = '<li class="empty-list">No friends yet. Use + to find friends.</li>';
        return;
    }
    list.innerHTML = friends.map((f) => {
        const presence = presenceInfo(f.id);
        const online = presence.online;
        const noteDot = f.has_note || f.note;
        return `
            <li class="conv-item" data-friend-id="${f.id}">
                <span class="avatar-wrap">
                    ${noteDot ? '<span class="note-indicator" aria-hidden="true"></span>' : ''}
                    ${avatarImg(f.avatar, f.name, f.username)}
                    ${online ? '<span class="online-dot" aria-hidden="true"></span>' : ''}
                </span>
                <div class="body">
                    <div class="conv-row"><span class="conv-name">${esc(f.name)}</span></div>
                    <div class="conv-bottom"><span class="conv-preview">@${esc(f.username)}</span></div>
                </div>
                <div class="action-row">
                    <button class="row-icon-btn" type="button" data-friend-message="${f.id}" title="Message" aria-label="Message ${esc(f.name)}">💬</button>
                    <button class="row-icon-btn danger" type="button" data-friend-remove="${f.id}" title="Remove friend" aria-label="Remove ${esc(f.name)}">✕</button>
                </div>
            </li>`;
    }).join('');
}

async function loadFriends(opts = {}) {
    const silent = opts.silent === true;
    try {
        const data = await api('/api/friends');
        state.friends = data.friends?.data ?? data.friends ?? [];
        state.friendRequests.incoming = data.incoming?.data ?? data.incoming ?? [];
        state.friendRequests.outgoing = data.outgoing?.data ?? data.outgoing ?? [];
        state.friendRequests.counts = data.counts ?? {};
        state.requestsUI.receivedError = null;
        state.requestsUI.sentError = null;
    } catch (error) {
        console.warn('friends load failed', error);
        if (!silent) {
            state.requestsUI.receivedError = 'Couldn’t load requests.';
            state.requestsUI.sentError = 'Couldn’t load requests.';
        }
    } finally {
        renderFriends();
        renderRequestsUI();
    }
}

async function loadMessageRequests(opts = {}) {
    const silent = opts.silent === true;
    try {
        const data = await api('/api/message-requests');
        state.messageRequests.incoming = data.incoming?.data ?? data.incoming ?? [];
        state.messageRequests.sent = data.sent?.data ?? data.sent ?? [];
        state.messageRequests.counts = data.counts ?? {};
        state.requestsUI.receivedError = null;
        state.requestsUI.sentError = null;
    } catch (error) {
        console.warn('message requests load failed', error);
        if (!silent) {
            state.requestsUI.receivedError = 'Couldn’t load requests.';
            state.requestsUI.sentError = 'Couldn’t load requests.';
        }
    } finally {
        renderRequestsUI();
    }
}

async function loadRequests(opts = {}) {
    const showLoading = opts.showLoading !== false;
    const seq = ++state.requestsUI.loadSeq;
    if (showLoading) {
        state.requestsUI.loading = true;
        state.requestsUI.receivedError = null;
        state.requestsUI.sentError = null;
        renderRequestsUI();
    }
    const results = await Promise.allSettled([api('/api/friends'), api('/api/message-requests')]);
    if (seq !== state.requestsUI.loadSeq) return;
    const [friendsRes, msgRes] = results;

    if (friendsRes.status === 'fulfilled') {
        const data = friendsRes.value;
        state.friends = data.friends?.data ?? data.friends ?? [];
        state.friendRequests.incoming = data.incoming?.data ?? data.incoming ?? [];
        state.friendRequests.outgoing = data.outgoing?.data ?? data.outgoing ?? [];
        state.friendRequests.counts = data.counts ?? {};
    } else {
        console.warn('friends load failed', friendsRes.reason);
        state.requestsUI.receivedError = 'Couldn’t load requests.';
        state.requestsUI.sentError = 'Couldn’t load requests.';
    }

    if (msgRes.status === 'fulfilled') {
        const data = msgRes.value;
        state.messageRequests.incoming = data.incoming?.data ?? data.incoming ?? [];
        state.messageRequests.sent = data.sent?.data ?? data.sent ?? [];
        state.messageRequests.counts = data.counts ?? {};
        if (friendsRes.status === 'fulfilled') {
            state.requestsUI.receivedError = null;
            state.requestsUI.sentError = null;
        }
    } else {
        console.warn('message requests load failed', msgRes.reason);
        state.requestsUI.receivedError = 'Couldn’t load requests.';
        state.requestsUI.sentError = 'Couldn’t load requests.';
    }

    state.requestsUI.loading = false;
    renderFriends();
    renderRequestsUI();
    if (state.sideTab === 'requests' && state.requestsUI.subTab === 'received') {
        markRequestsSeen();
    }
}

function findRequest(kind, id, direction = null) {
    if (kind === 'friend') {
        const list = direction === 'outgoing' ? state.friendRequests.outgoing : state.friendRequests.incoming;
        return (list || []).find((r) => Number(r.id) === Number(id));
    }
    const list = direction === 'outgoing' ? state.messageRequests.sent : state.messageRequests.incoming;
    return (list || []).find((r) => Number(r.id) === Number(id));
}

function removeRequest(kind, id, direction) {
    if (kind === 'friend') {
        if (direction === 'incoming') state.friendRequests.incoming = (state.friendRequests.incoming || []).filter((r) => Number(r.id) !== Number(id));
        else state.friendRequests.outgoing = (state.friendRequests.outgoing || []).filter((r) => Number(r.id) !== Number(id));
        const unseen = (state.friendRequests.incoming || []).filter((r) => r.unseen).length;
        if (state.friendRequests.counts) state.friendRequests.counts.unseen_incoming = unseen;
    } else {
        if (direction === 'incoming') state.messageRequests.incoming = (state.messageRequests.incoming || []).filter((r) => Number(r.id) !== Number(id));
        else state.messageRequests.sent = (state.messageRequests.sent || []).filter((r) => Number(r.id) !== Number(id));
        const unseen = (state.messageRequests.incoming || []).filter((r) => r.unseen).length;
        if (state.messageRequests.counts) state.messageRequests.counts.unseen_incoming = unseen;
    }
}

function upsertRequest(kind, direction, req) {
    if (!req) return;
    if (kind === 'friend') {
        const key = direction === 'incoming' ? 'incoming' : 'outgoing';
        const list = state.friendRequests[key] || [];
        const idx = list.findIndex((r) => Number(r.id) === Number(req.id));
        if (idx >= 0) list[idx] = req; else list.unshift(req);
        state.friendRequests[key] = list;
        if (state.friendRequests.counts) state.friendRequests.counts.unseen_incoming = (state.friendRequests.incoming || []).filter((r) => r.unseen).length;
    } else {
        const key = direction === 'incoming' ? 'incoming' : 'sent';
        const list = state.messageRequests[key] || [];
        const idx = list.findIndex((r) => Number(r.id) === Number(req.id));
        if (idx >= 0) list[idx] = req; else list.unshift(req);
        state.messageRequests[key] = list;
        if (state.messageRequests.counts) state.messageRequests.counts.unseen_incoming = (state.messageRequests.incoming || []).filter((r) => r.unseen).length;
    }
}

function openRequestComposer(user) {
    state.currentRequest = { mode: 'compose', user };
    $('request-details').innerHTML = `
        <div class="member-row">
            ${avatarImg(user.avatar, user.name, user.username, 'sm')}
            <span class="who">${esc(user.name)}<small>@${esc(user.username || '')}</small></span>
        </div>
        <p class="muted" style="margin-top:8px;font-size:12.5px;">You’re not friends yet. Send a message request to start a conversation.</p>`;
    if ($('request-body')) {
        $('request-body').value = '';
        $('request-body').focus();
    }
    $('request-modal')?.classList.add('show');
}

async function handleRequestAction(action, kind, id) {
    const inFlightKey = reqInFlightKey(kind, id, action);
    if (state.requestsUI.inFlight.has(inFlightKey)) return;
    const isOutgoing = ['friend-cancel', 'message-cancel'].includes(action);
    const direction = isOutgoing ? 'outgoing' : 'incoming';
    const item = findRequest(kind, id, direction);
    if (!item) {
        await loadRequests({ showLoading: false });
        return;
    }

    if (action === 'friend-cancel' && !confirm('Cancel this friend request?')) return;

    setReqInFlight(kind, id, action, true);
    renderRequestsUI();

    const restore = (snapshot) => {
        if (!findRequest(kind, id, direction)) upsertRequest(kind, direction, snapshot);
    };

    try {
        if (kind === 'friend' && action === 'friend-accept') {
            const res = await api(`/api/friends/${id}/accept`, { method: 'POST' });
            removeRequest('friend', id, 'incoming');
            if (res?.friend) state.friends = [res.friend, ...(state.friends || []).filter((f) => Number(f.id) !== Number(res.friend.id))];
            renderFriends();
            renderRequestsUI();
            toast('Friend request accepted.', {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/friends/${id}/undo-accept`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: item.seen_at || new Date().toISOString() });
                        renderRequestsUI();
                        await Promise.all([loadFriends({ silent: true }), loadConversations(), loadNotes()]);
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await Promise.all([loadFriends({ silent: true }), loadConversations(), loadNotes()]);
        } else if (kind === 'friend' && action === 'friend-decline') {
            await api(`/api/friends/${id}/reject`, { method: 'POST' });
            removeRequest('friend', id, 'incoming');
            renderRequestsUI();
            toast('Friend request declined.', {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/friends/${id}/undo-reject`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: item.seen_at || new Date().toISOString() });
                        renderRequestsUI();
                        await loadFriends({ silent: true });
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await loadFriends({ silent: true });
        } else if (kind === 'friend' && action === 'friend-cancel') {
            await api(`/api/friends/${id}`, { method: 'DELETE' });
            removeRequest('friend', id, 'outgoing');
            renderRequestsUI();
            toast('Friend request cancelled.', {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/friends/${id}/undo-cancel`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: null });
                        renderRequestsUI();
                        await loadFriends({ silent: true });
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await loadFriends({ silent: true });
        } else if (kind === 'message' && action === 'message-accept') {
            const res = await api(`/api/message-requests/${id}/accept`, { method: 'POST' });
            removeRequest('message', id, 'incoming');
            renderRequestsUI();
            toast('Message request accepted.', {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/message-requests/${id}/undo-accept`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: item.seen_at || new Date().toISOString() });
                        renderRequestsUI();
                        await Promise.all([loadRequests({ showLoading: false }), loadFriends({ silent: true }), loadConversations(), loadNotes()]);
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await Promise.all([loadRequests({ showLoading: false }), loadFriends({ silent: true }), loadConversations(), loadNotes()]);
            if (res?.conversation_id) {
                activateSideTab('chats');
                await loadConversations();
                openConversation(res.conversation_id);
            }
        } else if (kind === 'message' && ['message-delete', 'message-block', 'message-report'].includes(action)) {
            const endpoint = action === 'message-delete' ? `/api/message-requests/${id}/delete`
                : action === 'message-block' ? `/api/message-requests/${id}/block`
                : `/api/message-requests/${id}/report`;
            const successText = action === 'message-block' ? 'Request blocked.' : action === 'message-report' ? 'Request reported.' : 'Request deleted.';
            await api(endpoint, { method: 'POST' });
            removeRequest('message', id, 'incoming');
            renderRequestsUI();
            toast(successText, {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/message-requests/${id}/undo-delete`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: item.seen_at || new Date().toISOString() });
                        renderRequestsUI();
                        await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
        } else if (kind === 'message' && action === 'message-cancel') {
            await api(`/api/message-requests/${id}`, { method: 'DELETE' });
            removeRequest('message', id, 'outgoing');
            renderRequestsUI();
            toast('Message request cancelled.', {
                actionLabel: 'Undo',
                onAction: async () => {
                    try {
                        await api(`/api/message-requests/${id}/undo-cancel`, { method: 'POST' });
                        restore({ ...item, status: 'pending', responded_at: null, unseen: false, seen_at: null });
                        renderRequestsUI();
                        await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
                    } catch (err) { toast(err.message); await loadRequests({ showLoading: false }); }
                },
            });
            await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
        }
    } catch (error) {
        const msg = String(error?.message || '');
        if (/no longer pending|Cannot undo|409/i.test(msg)) {
            toast('This request is no longer available.');
        } else {
            toast(msg || 'Something went wrong.');
        }
        await loadRequests({ showLoading: false });
    } finally {
        setReqInFlight(kind, id, action, false);
        renderRequestsUI();
    }
}

/* ================================================================
 * Find friends modal & search
 * ================================================================ */
async function searchFindFriends(query) {
    const box = $('friend-results');
    if (!box) return;
    try {
        const users = await api(`/api/users?scope=find-friends&q=${encodeURIComponent(query || '')}`);
        if (!users.length) {
            box.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:12.5px">No users found.</div>';
            return;
        }
        box.innerHTML = users.map((u) => `
            <div class="member-row" data-find-user-id="${u.id}" style="gap:10px">
                ${avatarImg(u.avatar, u.name, u.username, 'sm')}
                <span class="who"><strong>${esc(u.name)}</strong><small>@${esc(u.username)}</small></span>
                <span class="action-row">
                    <button class="mini-btn primary" type="button" data-find-add='${esc(JSON.stringify({ id: u.id, name: u.name, username: u.username, avatar: u.avatar }))}'>Add friend</button>
                    <button class="mini-btn" type="button" data-find-message='${esc(JSON.stringify({ id: u.id, name: u.name, username: u.username, avatar: u.avatar, conversation_id: u.conversation_id }))}'>Message</button>
                </span>
            </div>`).join('');
    } catch (error) {
        toast(error.message);
    }
}

async function sendFriendRequestTo(user) {
    const note = ($('friend-request-note')?.value || '').trim();
    try {
        await api('/api/friends', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: user.id, note: note || null }),
        });
        toast('Friend request sent.');
        $('find-friends-modal')?.classList.remove('show');
        await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
    } catch (error) {
        toast(error.message);
    }
}

async function handleFindMessageUser(user) {
    try {
        if (user.conversation_id) {
            toast('A conversation already exists. Check your requests or chats.');
            return;
        }
        $('find-friends-modal')?.classList.remove('show');
        openRequestComposer(user);
    } catch (error) {
        toast(error.message);
    }
}

/* ================================================================
 * Chat header / info / mute / search-in-chat
 * ================================================================ */
function updateMuteButton() {
    const btn = $('chat-mute-btn');
    if (!btn) return;
    const muted = state.active ? isConvMuted(state.active.id) : false;
    btn.setAttribute('aria-pressed', String(muted));
    btn.classList.toggle('active-state', muted);
    btn.title = muted ? 'Unmute conversation' : 'Mute conversation';
    btn.setAttribute('aria-label', muted ? 'Unmute conversation' : 'Mute conversation');
}

function updateChatHeader() {
    if (!state.active) return;
    const nameEl = $('chat-name');
    const subEl = $('chat-subtitle');
    const avatarEl = $('chat-avatar');
    if (nameEl) nameEl.textContent = state.active.name ?? 'Conversation';
    if (avatarEl) {
        avatarEl.dataset.name = state.active.name ?? '';
        avatarEl.dataset.username = state.active.type === 'direct' ? (state.active.peer_username || peerKeyForConversation(state.active)) : (state.active.name ?? '');
        if (isCustomAvatarSrc(state.active.avatar)) {
            avatarEl.src = state.active.avatar;
        } else {
            applyAvatarFallback(avatarEl);
        }
    }

    let subtitle = '';
    if (state.active.type === 'group') {
        subtitle = state.active.subtitle ?? 'Group conversation';
    } else {
        const presence = presenceInfo(state.active.peer_id);
        subtitle = presence.label || 'Offline';
    }
    if (subEl) subEl.textContent = subtitle;

    const groupBtn = $('group-info-btn');
    if (groupBtn) groupBtn.hidden = state.active.type !== 'group';
    updateMuteButton();
    syncChatCallButton();
}

/** Bridge the existing vanilla chat state into the Alpine call button. */
function syncChatCallButton() {
    if (!state.active || state.active.type !== 'direct' || !state.active.peer_id) {
        window.__incognitoChatPeer = null;
        window.dispatchEvent(new CustomEvent('chat-peer-changed', { detail: null }));
        return;
    }

    const presence = presenceInfo(state.active.peer_id);
    const detail = {
        peer: {
            id: Number(state.active.peer_id),
            name: state.active.name ?? 'Unknown',
            username: state.active.peer_username || usernameFromSubtitle(state.active.subtitle),
            avatar: state.active.avatar ?? null,
            conversationId: Number(state.active.id),
        },
        online: presence.online,
        blocked: !!state.active.blocked,
    };

    // Alpine may initialize after the first conversation is opened; keep the
    // latest value so the button can hydrate itself.
    window.__incognitoChatPeer = detail;
    window.dispatchEvent(new CustomEvent('chat-peer-changed', { detail }));
}

function showChatView() {
    $('app')?.classList.add('show-chat');
}

function hideChatView() {
    $('app')?.classList.remove('show-chat');
}

function renderChatInfo() {
    if (!state.active) return;
    const body = $('chat-info-body');
    const title = $('chat-info-title');
    if (!body || !title) return;
    if (state.active.type === 'group') {
        title.textContent = 'Group info';
        body.innerHTML = `
            ${avatarImg(state.active.avatar, state.active.name, state.active.name, 'lg')}
            <div><strong>${esc(state.active.name)}</strong></div>
            <div class="meta">${esc(state.active.subtitle || 'Group conversation')}</div>
            <div class="meta">Use group settings for members and details.</div>`;
    } else {
        title.textContent = 'Profile';
        const presence = presenceInfo(state.active.peer_id);
        const username = state.active.peer_username || usernameFromSubtitle(state.active.subtitle) || '';
        body.innerHTML = `
            ${avatarImg(state.active.avatar, state.active.name, username, 'lg')}
            <div><strong>${esc(state.active.name)}</strong></div>
            <div class="meta">${username ? '@' + esc(username) : ''}</div>
            <div class="meta">${esc(presence.label || 'Offline')}</div>`;
    }
}

function setChatSearchOpen(open) {
    const wrap = $('chat-search-wrap');
    const btn = $('chat-search-btn');
    if (!wrap) return;
    wrap.hidden = !open;
    if (open) {
        $('chat-search-input')?.focus();
    } else {
        if ($('chat-search-input')) $('chat-search-input').value = '';
        state.chatSearchQuery = '';
        applyChatSearchFilter();
        btn?.focus();
    }
}

function applyChatSearchFilter() {
    const q = (state.chatSearchQuery || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#messages .msg-row[data-body]');
    rows.forEach((row) => {
        const text = (row.dataset.body || '').toLowerCase();
        const match = !q || text.includes(q);
        row.classList.toggle('search-dim', q ? !match : false);
        const bubble = row.querySelector('.bubble .bubble-inner-text');
        if (bubble) {
            const original = row.dataset.bodyDisplay || '';
            if (q && match) {
                bubble.innerHTML = highlightText(original, q);
            } else {
                bubble.textContent = original;
            }
        }
    });
    // show all date separators/groups if searching? keep simple: separators remain visible
}

function highlightText(text, q) {
    if (!q) return esc(text);
    const lower = text.toLowerCase();
    const idx = lower.indexOf(q);
    if (idx === -1) return esc(text);
    const before = text.slice(0, idx);
    const mid = text.slice(idx, idx + q.length);
    const after = text.slice(idx + q.length);
    return `${esc(before)}<mark class="chat-highlight">${esc(mid)}</mark>${highlightText(after, q)}`;
}

/* ================================================================
 * Messages rendering
 * ================================================================ */
function attachmentHtml(message) {
    const items = message.attachments ?? [];
    if (!items.length) return '';
    return `<div class="msg-attachments">${items.map((a) => {
        if (a.attachment_type === 'image') {
            return `<img class="msg-image" data-preview="${esc(a.preview_url)}" data-download="${esc(a.download_url)}" src="${esc(a.preview_url)}" alt="" data-name="" loading="lazy">`;
        }
        return `<div class="file-card">
                    <span aria-hidden="true">📄</span>
                    <span class="body"><span class="file-name">${esc(a.original_filename)}</span><br>
                        <span class="file-size">${esc(a.size_label ?? '')} ${a.mime_type ? '· ' + esc(a.mime_type) : ''}</span></span>
                    <a href="${esc(a.download_url)}" download title="Download" aria-label="Download ${esc(a.original_filename)}">⬇</a>
                </div>`;
    }).join('')}</div>`;
}

function canGroup(prev, cur) {
    if (!prev || !cur) return false;
    if (prev.type === 'call' || cur.type === 'call') return false;
    if (prev.sender_id !== cur.sender_id) return false;
    if (!!prev.is_unsent !== !!cur.is_unsent) return false;
    const a = new Date(prev.created_at).getTime();
    const b = new Date(cur.created_at).getTime();
    if (Number.isNaN(a) || Number.isNaN(b)) return false;
    return Math.abs(b - a) <= 5 * 60 * 1000;
}

function buildCallMessageRow(message) {
    const call = message.call || {};
    const label = message.preview || (call.status === 'declined'
        ? 'Declined audio call'
        : call.status === 'missed'
            ? 'Missed audio call'
            : 'Audio call');
    const missed = call.status === 'missed';
    const canCallBack = missed && state.active?.type === 'direct' && state.active.peer_id;

    const row = document.createElement('div');
    row.className = 'call-msg-row';
    row.dataset.id = message.id ?? '';
    row.dataset.sender = message.sender_id ?? '';
    row.innerHTML = `
        <div class="call-msg-card" role="note" aria-label="${esc(label)}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>
            <span>${esc(label)}</span>
            ${canCallBack ? `<button class="call-back-btn" type="button" data-call-back="${esc(state.active.peer_id)}" aria-label="Call ${esc(state.active.name ?? 'back')}">Call back</button>` : ''}
            <time datetime="${esc(message.created_at || '')}">${esc(message.time || formatTime(message.created_at))}</time>
        </div>`;
    return row;
}

function buildMessageRow(message, opts = {}) {
    if (message.type === 'call') return buildCallMessageRow(message);

    const mine = message.sender_id === state.me.id;
    const unsent = message.is_unsent === true;
    const isLastInGroup = !!opts.isLastInGroup;
    const showAvatar = !mine && isLastInGroup;
    const showSpacer = !mine && !isLastInGroup;
    const time = message.time ?? formatTime(message.created_at);
    const showTime = isLastInGroup; // visible by default on last; others hover via CSS class

    const row = document.createElement('div');
    row.className = `msg-row ${mine ? 'mine' : 'theirs'} ${isLastInGroup ? 'last-in-group' : ''}`;
    row.dataset.id = message.id ?? '';
    row.dataset.sender = message.sender_id ?? '';
    row.dataset.body = unsent ? '' : (message.body ?? '');
    row.dataset.bodyDisplay = unsent ? '' : (message.body ?? '');

    const ticks = !unsent ? statusTicksHtml(message) : '';
    const metaHtml = (time || ticks) ? `
        <span class="bubble-meta ${showTime ? '' : 'hover-time'}">
            ${time ? `<span class="msg-time">${esc(time)}</span>` : ''}
            ${ticks}
        </span>` : '';

    let bubblePart = '';
    if (unsent) {
        const who = Number(message.unsent_by) === state.me.id ? 'You' : (message.sender_name ?? 'Someone');
        const label = Number(message.unsent_by) === state.me.id ? 'You unsent a message' : `${who} unsent a message`;
        bubblePart = `<div class="unsent-bubble">${esc(label)}</div>`;
    } else {
        const bodyText = message.body ? `<span class="bubble-inner-text">${esc(message.body)}</span>` : '';
        const hasBody = !!message.body;
        bubblePart = `${hasBody ? `<div class="bubble ${mine ? 'sent' : 'received'}"><span class="bubble-inner">${bodyText}${metaHtml}</span></div>` : ''}
            ${attachmentHtml(message)}
            ${!hasBody && metaHtml ? `<div class="bubble-meta ${showTime ? '' : 'hover-time'}" style="margin-top:2px">${time ? `<span class="msg-time" style="color:var(--muted)">${esc(time)}</span>` : ''}${ticks}</div>` : ''}`;
        // If has body, meta is inside bubble. If no body (attachments only), render meta below.
    }

    const avatarCol = mine ? '' : (showAvatar
        ? `<span class="msg-avatar-col">${avatarImg(message.sender_avatar, message.sender_name, message.sender_username, 'sm')}</span>`
        : `<span class="msg-avatar-col spacer"></span>`);

    row.innerHTML = `
        ${avatarCol}
        <div class="msg-content">
            ${bubblePart}
        </div>
        ${!unsent ? `<span class="msg-actions"><button class="msg-menu-btn" type="button" data-message-menu-id="${message.id}" aria-label="Message options" aria-haspopup="dialog" title="Message options">⋯</button></span>` : ''}`;
    return row;
}

function buildMessagesFragment(messages) {
    const fragment = document.createDocumentFragment();
    let prev = null;
    let prevDay = null;
    // precompute last-in-group flags
    const lastFlags = messages.map((m, i) => {
        const next = messages[i + 1];
        return !(next && canGroup(m, next));
    });
    let currentGroup = null;
    messages.forEach((message, i) => {
        const day = dayLabel(message.created_at);
        const dayKey = new Date(message.created_at).toDateString();
        if (day && dayKey !== prevDay) {
            const sep = document.createElement('div');
            sep.className = 'day-sep';
            sep.textContent = day;
            fragment.appendChild(sep);
            currentGroup = null;
            prevDay = dayKey;
        }

        if (message.type === 'call') {
            currentGroup = null;
            prev = message;
            fragment.appendChild(buildCallMessageRow(message));
            return;
        }

        const mine = message.sender_id === state.me.id;
        const grouped = currentGroup && canGroup(prev, message);
        if (!grouped) {
            currentGroup = document.createElement('div');
            currentGroup.className = `msg-group ${mine ? 'mine' : 'theirs'}${fragment.childNodes.length ? ' group-gap' : ''}`;
            // If previous sibling was a day-sep, don't double large gap; group-gap is fine
            fragment.appendChild(currentGroup);
        } else {
            // ensure class reflects sender (same)
            currentGroup.classList.toggle('mine', mine);
            currentGroup.classList.toggle('theirs', !mine);
        }

        const row = buildMessageRow(message, { isLastInGroup: lastFlags[i] });
        currentGroup.appendChild(row);
        prev = message;
    });
    return fragment;
}

function renderMessages() {
    const box = $('messages');
    if (!box) return;
    box.innerHTML = '';
    if (!state.activeMessages.length) {
        // empty conversation - keep area empty (composer/empty states handled elsewhere)
    } else {
        box.appendChild(buildMessagesFragment(state.activeMessages));
    }
    if (state.peerTyping) showTypingRow();
    updateJumpLatest();
    applyChatSearchFilter();
}

function rerenderMessagesPreserveScroll() {
    const box = $('messages');
    if (!box) { renderMessages(); return; }
    const atBottom = isNearBottom();
    const prevHeight = box.scrollHeight;
    const prevTop = box.scrollTop;
    renderMessages();
    if (atBottom) {
        scrollToLatestRow(false);
    } else {
        box.scrollTop = prevTop + (box.scrollHeight - prevHeight);
        updateJumpLatest();
    }
}

function appendMessageToState(message, { rerender = true, scroll = true } = {}) {
    // Avoid duplicates
    if (state.activeMessages.some((m) => Number(m.id) === Number(message.id))) return null;
    state.activeMessages.push(message);
    if (rerender) {
        rerenderMessagesPreserveScroll();
        if (scroll) scrollToLatestRow();
    }
    return message;
}

function prependMessagesToState(messages) {
    const ids = new Set(state.activeMessages.map((m) => Number(m.id)));
    const fresh = messages.filter((m) => !ids.has(Number(m.id)));
    if (!fresh.length) return;
    state.activeMessages = [...fresh, ...state.activeMessages];
}

function scheduleConversationsReload(delay = 80) {
    clearTimeout(scheduleConversationsReload.timer);
    scheduleConversationsReload.timer = setTimeout(loadConversations, delay);
}

function unsentPreviewFor(message) {
    const byMe = Number(message.unsent_by) === Number(state.me.id);
    return byMe ? 'You unsent a message' : `${message.sender_name ?? 'Someone'} unsent a message`;
}

function updateSidebarForUnsent(id, event) {
    const conversationId = Number(event.conversation_id ?? event.message?.conversation_id);
    const conv = state.conversations.find((c) => Number(c.id) === conversationId);
    if (!conv || Number(conv.last_message?.id) !== Number(id)) return;

    const payload = event.message ?? {};
    conv.last_message = {
        ...conv.last_message,
        ...payload,
        id: Number(payload.id ?? conv.last_message.id),
        is_unsent: true,
        body: '',
        attachments: [],
        seen_by: [],
        status: 'unsent',
        // Wording is per-viewer ("You" vs the sender's name), so never reuse
        // the broadcast preview that was rendered with a null viewer.
        preview: unsentPreviewFor(payload),
    };
    renderConversationList();
}

function applyUnsent(event) {
    const id = Number(event.id ?? event.message_id);
    if (!id) return;

    const hiddenFor = (event.hidden_for ?? []).map(Number);
    const mode = event.mode || (event.message ? 'everyone' : 'me');

    if (hiddenFor.includes(state.me.id)) {
        removeMessageForMe(id, { reload: false });
        scheduleConversationsReload();
        return;
    }

    if (mode === 'everyone') {
        const local = state.activeMessages.find((m) => Number(m.id) === id);
        if (local) {
            if (event.message) {
                // Replace in place: same array index, id, createdAt, sort key.
                Object.assign(local, event.message);
                local.preview = unsentPreviewFor(local);
            } else {
                local.is_unsent = true;
                local.body = '';
                local.attachments = [];
                local.seen_by = [];
                local.status = 'unsent';
                local.preview = event.preview || unsentPreviewFor(local);
            }
            if (state.active) rerenderMessagesPreserveScroll();
        }
        updateSidebarForUnsent(id, event);
        scheduleConversationsReload();
    }
}

function removeMessageForMe(id, opts = {}) {
    const target = Number(id);
    const before = state.activeMessages.length;
    state.activeMessages = state.activeMessages.filter((m) => Number(m.id) !== target);
    if (state.activeMessages.length !== before && state.active) {
        rerenderMessagesPreserveScroll();
    }
    if (opts.reload !== false) scheduleConversationsReload();
}

function showTypingRow() {
    const box = $('messages');
    if (!box) return;
    let row = box.querySelector('.typing-row');
    if (!row) {
        row = document.createElement('div');
        row.className = 'typing-row';
        const avatar = state.active?.avatar || '';
        const name = state.active?.name || 'Someone';
        const username = state.active?.peer_username || peerKeyForConversation(state.active) || name;
        row.innerHTML = `
            <span class="msg-avatar-col">${avatarImg(avatar, name, username, 'sm')}</span>
            <span class="typing-bubble" aria-label="${esc(name)} is typing"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>`;
        box.appendChild(row);
        scrollToLatestRow();
    }
}

function hideTypingRow() {
    const row = $('messages')?.querySelector('.typing-row');
    if (row) row.remove();
}

function showTypingIndicator() {
    if (!state.active) return;
    state.peerTyping = true;
    showTypingRow();
    clearTimeout(state.typingHideTimer);
    state.typingHideTimer = setTimeout(() => {
        state.peerTyping = false;
        hideTypingRow();
    }, 2200);
}

function hideTypingIndicator() {
    state.peerTyping = false;
    clearTimeout(state.typingHideTimer);
    hideTypingRow();
}

/* ================================================================
 * Open conversation / history / socket
 * ================================================================ */
function handleAccessRevoked(conversationId) {
    if (state.active?.id !== conversationId) {
        loadConversations();
        return;
    }
    if (state.activeChannel) window.Echo.leave(state.activeChannel);
    state.activeChannel = null;
    state.active = null;
    state.activeGroup = null;
    state.activeMessages = [];
    state.cursor = null;
    hideTypingIndicator();
    $('chat')?.classList.remove('open');
    if ($('empty-chat')) $('empty-chat').style.display = '';
    $('settings-modal')?.classList.remove('show');
    hideChatView();
    toast('You no longer have access to this conversation.');
    loadConversations();
}

async function openConversation(id) {
    if (!id) return;
    id = Number(id);

    if (state.active?.id === id) {
        showChatView();
        return;
    }

    if (state.activeChannel) {
        window.Echo.leave(state.activeChannel);
        state.activeChannel = null;
    }

    const conversation = state.conversations.find((c) => c.id === id);
    state.active = conversation
        ? { ...conversation, peer_username: conversation.peer_username || peerKeyForConversation(conversation) }
        : { id, type: 'direct', name: 'Conversation', avatar: '' };
    state.activeGroup = null;
    state.activeMessages = [];
    state.cursor = null;
    state.loadingOlder = false;
    state.unseen = 0;
    state.peerTyping = false;
    clearTimeout(state.typingHideTimer);

    if ($('empty-chat')) $('empty-chat').style.display = 'none';
    $('chat')?.classList.add('open');
    showChatView();
    renderMessageSkeleton();
    if ($('jump-latest')) $('jump-latest').hidden = true;
    if ($('message-input')) {
        $('message-input').value = '';
        autoGrowTextarea($('message-input'));
    }
    state.pendingAttachments = [];
    renderAttachmentPreviews();
    clearAttachmentError();
    setChatSearchOpen(false);
    hideTypingIndicator();
    updateChatHeader();
    updateSendButton();
    renderConversationList();

    if (conversation) {
        conversation.unread = 0;
        renderConversationList();
    }

    try {
        state.messagesLoading = true;
        const history = await api(`/api/conversations/${id}`);
        if (state.active?.id !== id) return;
        state.cursor = history.next_cursor;
        state.activeMessages = [...(history.data || [])].reverse();

        if (history.counts) {
            applyUnreadCounts(history.counts);
        } else if (conversation) {
            conversation.unread = history.unread ?? 0;
            renderConversationList();
        }

        renderMessages();
        scrollToLatestRow(false);
    } catch (error) {
        if (state.active?.id === id) {
            $('messages').innerHTML = '<div class="empty-list">Could not load messages.</div>';
            toast(error.message);
        }
    } finally {
        state.messagesLoading = false;
    }

    const channel = `conversation.${id}`;
    state.activeChannel = channel;

    window.Echo.private(channel)
        .listen('.MessageSent', (message) => {
            if (shouldPlayReceivedSound(message)) playSound('received');
            // update conversation preview/sidebar even if not active
            const conv = state.conversations.find((c) => c.id === message.conversation_id);
            if (conv) {
                conv.last_message = message;
                conv.last_at = message.created_at;
            }
            if (state.active?.id !== message.conversation_id) {
                renderConversationList();
                return;
            }
            const wasAtBottom = isNearBottom();
            const mine = message.sender_id === state.me.id;
            appendMessageToState(message, { rerender: true, scroll: false });
            if (mine || wasAtBottom) {
                scrollToLatestRow(false);
            } else {
                state.unseen += 1;
                updateJumpLatest();
            }
            if (!mine && message.type !== 'call' && document.visibilityState === 'visible') {
                markMessageSeen(message.id);
            }
            hideTypingIndicator();
            renderConversationList();
        })
        .listen('.MessageSeen', (event) => {
            if (Number(event.user_id) === state.me.id) {
                if (event.counts) {
                    applyUnreadCounts(event.counts);
                } else {
                    const own = state.conversations.find((c) => c.id === event.conversation_id);
                    if (own && own.unread) { own.unread = 0; renderConversationList(); }
                }
                return;
            }
            const reader = {
                user_id: event.user_id,
                name: event.name,
                avatar: event.avatar,
                read_at: event.read_at,
            };
            const msg = state.activeMessages.find((m) => Number(m.id) === Number(event.message_id));
            if (msg) {
                msg.status = 'seen';
                const readers = msg.seen_by ?? [];
                if (!readers.some((r) => Number(r.user_id) === Number(event.user_id))) {
                    msg.seen_by = [...readers, reader];
                }
            }
            if (state.active?.id === event.conversation_id) {
                const row = document.querySelector(`.msg-row[data-id="${event.message_id}"]`);
                if (row) setStatusTicks(row, true);
            }
            const conv = state.conversations.find((c) => c.id === event.conversation_id);
            if (conv?.last_message?.id === event.message_id) {
                const readers = conv.last_message.seen_by ?? [];
                if (!readers.some((r) => r.user_id === event.user_id)) {
                    conv.last_message.status = 'seen';
                    conv.last_message.seen_by = [...readers, reader];
                    renderConversationList();
                }
            }
        })
        .listen('.MessageUnsent', (event) => {
            applyUnsent(event);
        })
        .listenForWhisper('typing', (event) => {
            if (state.active?.id !== id || Number(event.from) === state.me.id) return;
            showTypingIndicator();
        })
        .listen('.GroupUpdated', (event) => {
            const local = state.conversations.find((c) => c.id === id);
            if (local) {
                if (event.name) local.name = event.name;
                if (event.avatar) local.avatar = event.avatar;
                if (event.member_count != null) local.subtitle = `${event.member_count} members`;
                if (state.active?.id === id) {
                    state.active.name = local.name;
                    state.active.avatar = local.avatar;
                    state.active.subtitle = local.subtitle;
                    updateChatHeader();
                }
                renderConversationList();
            }
            if (state.settingsGroupId === event.group_id) loadGroupSettings(event.group_id);
        })
        .listen('.AccessRevoked', (event) => {
            if (Number(event.user_id) === state.me.id) {
                handleAccessRevoked(id);
            }
        });

    if (conversation?.type === 'group') {
        loadGroupSettings(conversation.group_id);
    }
}

async function loadOlderMessages() {
    if (state.loadingOlder || !state.active || !state.cursor) return;
    const conversationId = state.active.id;
    const cursor = state.cursor;
    state.loadingOlder = true;
    try {
        const page = await api(`/api/conversations/${conversationId}?cursor=${encodeURIComponent(cursor)}`);
        if (state.active?.id !== conversationId) return;
        const box = $('messages');
        const prevHeight = box.scrollHeight;
        const prevTop = box.scrollTop;
        prependMessagesToState([...(page.data || [])].reverse());
        renderMessages();
        box.scrollTop = prevTop + (box.scrollHeight - prevHeight);
        state.cursor = page.next_cursor;
        updateJumpLatest();
    } catch (error) {
        toast(error.message);
    } finally {
        state.loadingOlder = false;
    }
}

/* ================================================================
 * Composer
 * ================================================================ */
function updateSendButton() {
    const input = $('message-input');
    const hasContent = Boolean(input?.value.trim()) || state.pendingAttachments.length > 0;
    if ($('send-btn')) $('send-btn').disabled = !state.active || !hasContent;
}

function showAttachmentError(message) {
    const box = $('attachment-error');
    if (!box) return;
    box.textContent = message;
    box.hidden = false;
}

function clearAttachmentError() {
    const box = $('attachment-error');
    if (!box) return;
    box.textContent = '';
    box.hidden = true;
}

function renderAttachmentPreviews() {
    const box = $('attachment-previews');
    if (!box) return;
    if (!state.pendingAttachments.length) {
        box.hidden = true;
        box.innerHTML = '';
        return;
    }
    box.hidden = false;
    box.innerHTML = state.pendingAttachments.map((file, i) => `
        <span class="attach-chip"><span title="${esc(file.name)}">${esc(file.name)}</span><button type="button" data-remove-attach="${i}" aria-label="Remove ${esc(file.name)}">✕</button></span>
    `).join('');
}

function handleAttachmentSelection(files) {
    clearAttachmentError();
    const incoming = Array.from(files || []);
    if (!incoming.length) return;
    const oversize = incoming.find((f) => f.size > 20 * 1024 * 1024);
    if (oversize) {
        showAttachmentError("File can't be larger than 20 MB");
        return;
    }
    state.pendingAttachments = [...state.pendingAttachments, ...incoming].slice(0, 5);
    renderAttachmentPreviews();
    updateSendButton();
}

function uploadMessage(formData) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `/api/conversations/${state.active.id}/messages`);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            const barWrap = $('upload-progress');
            if (barWrap) {
                barWrap.hidden = false;
                const bar = barWrap.querySelector('.bar');
                if (bar) bar.style.width = `${percent}%`;
            }
        });

        xhr.addEventListener('load', () => {
            const barWrap = $('upload-progress');
            if (barWrap) {
                barWrap.hidden = true;
                const bar = barWrap.querySelector('.bar');
                if (bar) bar.style.width = '0%';
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                try { resolve(JSON.parse(xhr.responseText)); }
                catch { resolve(null); }
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
            const barWrap = $('upload-progress');
            if (barWrap) barWrap.hidden = true;
            reject(new Error('Upload failed. Check your connection and try again.'));
        });

        xhr.send(formData);
    });
}

/* Composer textarea grows with its content up to max-height (about 5-6
   lines), then keeps that height and scrolls internally. Measuring with
   height:auto avoids the sub-pixel overflow that used to force a scrollbar
   on a single short line. */
const COMPOSER_MAX_HEIGHT = 140;

function autoGrowTextarea(el) {
    if (!el) return;
    el.style.overflowY = 'hidden';
    el.style.height = 'auto';

    // A composer inside the not-yet-open chat view has no layout box; keep
    // its natural one-row height instead of measuring 0 and collapsing.
    if (!el.offsetParent) {
        el.style.height = '';
        return;
    }

    const next = Math.min(el.scrollHeight, COMPOSER_MAX_HEIGHT);
    el.style.height = `${next}px`;
    el.style.overflowY = el.scrollHeight > COMPOSER_MAX_HEIGHT ? 'auto' : 'hidden';
}

/* Emoji picker */
const EMOJIS = ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','😉','😍','😘','😋','😎','🤗','🤔','😐','🙄','😴','😢','😭','😤','😡','👍','👎','👏','🙏','💪','🎉','🔥','❤️','💙','💚','✨','⭐','✅','❌','👀','💬','📎','📷','🎵','🚀','🌙','☀️','🍕','☕'];

function renderEmojiPicker() {
    const picker = $('emoji-picker');
    if (!picker) return;
    picker.innerHTML = EMOJIS.map((e) => `<button type="button" data-emoji="${e}" aria-label="${e}">${e}</button>`).join('');
}

/* ================================================================
 * Groups
 * ================================================================ */
async function searchGroupMembers(query) {
    try {
        renderGroupMemberChoices(await api(`/api/users?q=${encodeURIComponent(query || '')}`));
    } catch (error) {
        toast(error.message);
    }
}

function renderGroupMemberChoices(users) {
    const box = $('group-members');
    if (!box) return;
    if (!users.length) {
        box.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:12.5px">No users found.</div>';
        return;
    }
    box.innerHTML = users.map((user) => {
        const selected = state.selectedMembers.has(user.id);
        return `
            <div class="member-row">
                ${avatarImg(user.avatar, user.name, user.username, 'sm')}
                <span class="who">${esc(user.name)}<small>@${esc(user.username)}</small></span>
                <button type="button" class="btn ${selected ? 'primary' : ''}" data-member-id="${user.id}">${selected ? '✓ Added' : 'Add'}</button>
            </div>`;
    }).join('');
}

async function loadGroupSettings(groupId) {
    if (!groupId) return;
    state.settingsGroupId = groupId;
    try {
        const group = await api(`/api/groups/${groupId}`);
        state.activeGroup = group;
        $('settings-title').textContent = group.name;
        $('leave-group-btn').hidden = group.creator_id === state.me.id;
        $('delete-group-btn').hidden = !group.is_admin;

        $('settings-members').innerHTML = (group.members || []).map((member) => `
            <div class="member-row">
                ${avatarImg(member.avatar, member.name, member.username, 'sm')}
                <span class="who">${esc(member.name)}<small>@${esc(member.username)} · ${esc(member.role || 'member')}</small></span>
                ${group.is_admin && member.id !== group.creator_id
                    ? `<button class="btn danger" type="button" data-remove-member="${member.id}">Remove</button>` : ''}
            </div>`).join('');
    } catch (error) {
        toast(error.message);
    }
}

/* ================================================================
 * Notifications
 * ================================================================ */
function notificationText(event) {
    const d = event.data || {};
    switch (event.type) {
        case 'friend_request_received': return `${d.name || 'Someone'} sent you a friend request`;
        case 'friend_request_accepted': return `${d.name || 'Someone'} accepted your friend request`;
        case 'friend_request_rejected': return `${d.name || 'Someone'} declined your friend request`;
        case 'friend_request_cancelled': return `${d.name || 'Someone'} cancelled a friend request`;
        case 'message_request_received': return `${d.name || 'Someone'} sent you a message request`;
        case 'message_request_accepted': return `${d.name || 'Someone'} accepted your message request`;
        case 'message_request_deleted': return `${d.name || 'Someone'} declined your message request`;
        case 'message_request_cancelled': return `${d.name || 'Someone'} cancelled a message request`;
        case 'note_posted': return `${d.name || 'A friend'} added a note`;
        case 'note_updated': return `${d.name || 'A friend'} updated a note`;
        case 'note_deleted': return `${d.name || 'A friend'} removed a note`;
        case 'missed_call': return `Missed audio call from ${d.name || 'a friend'}`;
        default: return 'New notification';
    }
}

function updateNotificationBadge() {
    const badge = $('notification-badge');
    if (!badge) return;
    const count = Math.max(0, Number(state.notificationCount) || 0);
    badge.textContent = count ? (count > 9 ? '9+' : String(count)) : '';
    badge.hidden = !count;
    badge.setAttribute(
        'aria-label',
        count ? `${count} unread notification${count === 1 ? '' : 's'}` : 'No unread notifications'
    );
    $('notification-btn')?.setAttribute(
        'aria-label',
        count ? `Notifications, ${count} unread` : 'Notifications'
    );
}

async function loadNotifications() {
    try {
        const page = await api('/api/notifications');
        const items = page.data?.data ?? page.data ?? (Array.isArray(page) ? page : []);
        state.notificationCount = page.unread_count ?? items.filter((n) => !n.read_at).length;
        updateNotificationBadge();
        const list = $('notifications-list');
        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<div class="empty-list">No notifications yet.</div>';
            return;
        }
        list.innerHTML = items.map((n) => {
            const unread = !n.read_at;
            return `
            <div class="member-row notification-row${unread ? ' unread' : ''}"
                 data-notification-id="${n.id}"
                 data-read="${unread ? '0' : '1'}"
                 data-notification-type="${esc(n.type || '')}"
                 data-conversation-id="${esc(n.data?.conversation_id || '')}"
                 role="button" tabindex="0"
                 aria-label="${esc(notificationText(n))}${unread ? ', unread' : ''}">
                <span class="who" style="font-size:13px">${esc(notificationText(n))}<small>${esc(n.created_at ? new Date(n.created_at).toLocaleString() : '')}</small></span>
                ${unread ? '<span class="notification-dot" aria-hidden="true"></span>' : ''}
            </div>`;
        }).join('');
    } catch (error) {
        console.warn('notifications load failed', error);
    }
}

function markNotificationRowRead(row) {
    if (!row || row.dataset.read === '1') return;
    row.dataset.read = '1';
    row.classList.remove('unread');
    row.querySelector('.notification-dot')?.remove();
    row.setAttribute('aria-label', row.getAttribute('aria-label')?.replace(/, unread$/, '') || '');
}

async function markNotificationRead(id) {
    const row = document.querySelector(`[data-notification-id="${id}"]`);
    if (!row || row.dataset.read === '1') return;

    const previousCount = state.notificationCount;
    markNotificationRowRead(row);

    // Optimistic: one notification read means one fewer unread badge item.
    state.notificationCount = Math.max(0, previousCount - 1);
    updateNotificationBadge();

    try {
        const result = await api(`/api/notifications/${id}/read`, { method: 'POST' });
        state.notificationCount = result.unread_count ?? state.notificationCount;
        updateNotificationBadge();
    } catch (error) {
        state.notificationCount = previousCount;
        updateNotificationBadge();
        toast(error.message);
        await loadNotifications();
    }
}

/* ================================================================
 * Delete message dialog
 * ================================================================ */
const DELETE_FOCUSABLE = 'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

function deleteDialogOpen() {
    return $('delete-message-modal')?.classList.contains('show') === true;
}

function resetDeleteButtonLabels() {
    const everyone = $('delete-everyone-btn');
    const forMe = $('delete-for-me-btn');
    if (everyone) everyone.querySelector('.delete-option-title').textContent = 'Delete for everyone';
    if (forMe) forMe.querySelector('.delete-option-title').textContent = 'Delete for you';
}

function applyDeleteAvailability() {
    const target = state.deleteTarget;
    if (!target) return;
    const everyone = $('delete-everyone-btn');
    const note = $('delete-seen-note');
    const blocked = !target.mine || target.seen;
    everyone.disabled = blocked;
    note.hidden = !blocked;
    note.textContent = target.seen
        ? 'Seen messages can only be deleted for you.'
        : 'Messages from others can only be deleted for you.';
}

function openDeleteDialog(id, trigger) {
    const message = state.activeMessages.find((m) => Number(m.id) === Number(id));
    if (!message) return;

    const mine = Number(message.sender_id) === Number(state.me.id);
    const seen = message.status === 'seen'
        || (message.seen_by ?? []).length > 0
        || message._deleteEveryoneBlocked === true;

    state.deleteTarget = { id: Number(message.id), message, trigger: trigger || null, mine, seen };
    state.deleteBusy = false;

    const modal = $('delete-message-modal');
    const error = $('delete-error');
    if (error) error.textContent = '';
    resetDeleteButtonLabels();
    if ($('delete-for-me-btn')) $('delete-for-me-btn').disabled = false;
    if ($('delete-cancel-btn')) $('delete-cancel-btn').disabled = false;
    applyDeleteAvailability();

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');

    requestAnimationFrame(() => {
        const everyone = $('delete-everyone-btn');
        const first = everyone && !everyone.disabled ? everyone : $('delete-for-me-btn');
        first?.focus();
    });
}

function closeDeleteDialog() {
    if (state.deleteBusy) return;
    const target = state.deleteTarget;
    const modal = $('delete-message-modal');
    if (!modal || !modal.classList.contains('show')) {
        state.deleteTarget = null;
        return;
    }

    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if ($('delete-error')) $('delete-error').textContent = '';
    state.deleteTarget = null;

    let focusTarget = target?.trigger;
    if (focusTarget && !document.contains(focusTarget)) {
        focusTarget = document.querySelector(`[data-message-menu-id="${target.id}"]`);
    }
    if (!focusTarget && target) {
        const box = $('messages');
        box?.setAttribute('tabindex', '-1');
        focusTarget = box;
    }
    focusTarget?.focus?.();
}

function setDeleteLoading(mode) {
    state.deleteBusy = true;
    const everyone = $('delete-everyone-btn');
    const forMe = $('delete-for-me-btn');
    const cancel = $('delete-cancel-btn');

    everyone.disabled = true;
    forMe.disabled = true;
    if (cancel) cancel.disabled = true;

    if (mode === 'everyone') {
        everyone.querySelector('.delete-option-title').textContent = 'Deleting…';
    } else {
        forMe.querySelector('.delete-option-title').textContent = 'Deleting…';
    }
}

async function confirmDelete(mode) {
    const target = state.deleteTarget;
    if (!target || state.deleteBusy) return;

    setDeleteLoading(mode);
    if ($('delete-error')) $('delete-error').textContent = '';

    try {
        const result = await api(`/api/messages/${target.id}/unsend`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode }),
        });

        if (mode === 'everyone') {
            applyUnsent(result);
            toast('Message deleted for everyone.');
        } else {
            removeMessageForMe(target.id);
            toast('Message deleted for you.');
        }

        state.deleteBusy = false;
        closeDeleteDialog();
    } catch (error) {
        state.deleteBusy = false;

        const message = String(error?.message || 'Could not delete the message.');
        if ($('delete-error')) $('delete-error').textContent = message;

        // The server may have discovered a seen receipt that our local
        // snapshot had not received yet. Reflect the authoritative decision.
        if (/seen/i.test(message) && target.mine) {
            target.seen = true;
            target.message._deleteEveryoneBlocked = true;
            applyDeleteAvailability();
        }

        resetDeleteButtonLabels();
        const everyone = $('delete-everyone-btn');
        const forMe = $('delete-for-me-btn');
        const cancel = $('delete-cancel-btn');
        if (everyone) everyone.disabled = target.seen || !target.mine;
        if (forMe) forMe.disabled = false;
        if (cancel) cancel.disabled = false;

        toast(message);
    }
}

function trapDeleteDialogFocus(event) {
    if (!deleteDialogOpen()) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        closeDeleteDialog();
        return;
    }

    if (event.key !== 'Tab') return;

    const dialog = $('delete-message-modal')?.querySelector('.delete-dialog');
    if (!dialog) return;
    const focusable = Array.from(dialog.querySelectorAll(DELETE_FOCUSABLE))
        .filter((el) => !el.hidden && el.offsetParent !== null);
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

/* ================================================================
 * Event wiring
 * ================================================================ */
function bindEvents() {
    // Global dropdown/menus close
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.menu-wrap')) closeDropdowns();
        if (!event.target.closest('.req-menu-wrap')) {
            document.querySelectorAll('.req-menu:not([hidden])').forEach((m) => {
                m.hidden = true;
                const wrap = m.closest('.req-menu-wrap');
                wrap?.querySelector('[data-req-menu-toggle]')?.setAttribute('aria-expanded', 'false');
            });
        }
        const picker = $('emoji-picker');
        if (picker && !picker.hidden && !event.target.closest('#emoji-picker') && !event.target.closest('#emoji-btn')) {
            picker.hidden = true;
        }
    });

    // Profile/menu
    $('me-menu-btn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleMenu('me-menu-btn', 'me-menu'); });
    $('sidebar-add-btn')?.addEventListener('click', (e) => { e.stopPropagation(); toggleMenu('sidebar-add-btn', 'sidebar-add-menu'); });

    // Sidebar tabs
    $('side-tab-chats')?.addEventListener('click', () => activateSideTab('chats'));
    $('side-tab-requests')?.addEventListener('click', () => activateSideTab('requests'));
    $('side-tab-friends')?.addEventListener('click', () => activateSideTab('friends'));

    // Sidebar chat search (filters existing chats)
    $('chat-search')?.addEventListener('input', debounce((e) => {
        state.chatFilter = e.target.value || '';
        renderConversationList();
    }, 150));

    // Add menu actions
    $('find-friends-open-btn')?.addEventListener('click', () => {
        closeDropdowns();
        openFindFriendsModal();
    });
    $('new-group-btn')?.addEventListener('click', () => {
        closeDropdowns();
        $('group-form')?.reset();
        state.selectedMembers.clear();
        $('group-modal')?.classList.add('show');
        searchGroupMembers('');
        $('group-name')?.focus();
    });

    // Conversation list clicks
    $('conversation-list')?.addEventListener('click', (event) => {
        const item = event.target.closest('.conv-item[data-id]');
        if (item) openConversationById(item.dataset.id);
    });
    $('conversation-list')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const item = event.target.closest('.conv-item[data-id]');
        if (item) { event.preventDefault(); openConversationById(item.dataset.id); }
    });

    // Back button (mobile)
    $('chat-back')?.addEventListener('click', () => hideChatView());

    // Chat header actions
    $('chat-search-btn')?.addEventListener('click', () => setChatSearchOpen($('chat-search-wrap')?.hidden !== false));
    $('chat-search-close')?.addEventListener('click', () => setChatSearchOpen(false));
    $('chat-search-input')?.addEventListener('input', (e) => {
        state.chatSearchQuery = e.target.value || '';
        applyChatSearchFilter();
    });
    $('chat-info-btn')?.addEventListener('click', () => {
        renderChatInfo();
        $('chat-info-modal')?.classList.add('show');
    });
    $('chat-mute-btn')?.addEventListener('click', () => {
        if (!state.active) return;
        const id = Number(state.active.id);
        if (state.muted.has(id)) state.muted.delete(id); else state.muted.add(id);
        persistMutedSet();
        updateMuteButton();
        toast(isConvMuted(id) ? 'Conversation muted' : 'Conversation unmuted');
    });
    $('group-info-btn')?.addEventListener('click', () => {
        if (state.active?.group_id) {
            $('settings-modal')?.classList.add('show');
            loadGroupSettings(state.active.group_id);
        }
    });

    // Messages scroll & jump
    $('messages')?.addEventListener('scroll', () => {
        if (isNearBottom()) state.unseen = 0;
        if ($('messages').scrollTop <= 60 && state.cursor) loadOlderMessages();
        updateJumpLatest();
    }, { passive: true });
    $('jump-latest')?.addEventListener('click', () => {
        scrollToLatestRow(true);
        $('message-input')?.focus();
    });

    // Composer
    $('composer')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.active) return;
        const input = $('message-input');
        const body = input.value.trim();
        if (!body && !state.pendingAttachments.length) return;

        const files = [...state.pendingAttachments];
        input.value = '';
        autoGrowTextarea(input);
        state.pendingAttachments = [];
        clearAttachmentError();
        renderAttachmentPreviews();
        updateSendButton();

        const data = new FormData();
        if (body) data.set('body', body);
        files.forEach((file) => data.append('attachments[]', file));

        try {
            await uploadMessage(data);
            playSound('sent');
        } catch (error) {
            if (body) input.value = body;
            autoGrowTextarea(input);
            state.pendingAttachments = files;
            renderAttachmentPreviews();
            updateSendButton();
            if (/larger than 20 MB/i.test(error.message)) {
                showAttachmentError("File can't be larger than 20 MB");
            } else {
                toast(error.message);
            }
        }
    });

    $('message-input')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            $('composer')?.requestSubmit();
        }
    });

    $('message-input')?.addEventListener('input', (event) => {
        const wasAtBottom = isNearBottom();
        updateSendButton();
        autoGrowTextarea(event.target);
        if (wasAtBottom) scrollToLatestRow();
        if (!state.activeChannel) return;
        const now = Date.now();
        if (now - state.lastWhisperSent < 800) return;
        state.lastWhisperSent = now;
        try { window.Echo.private(state.activeChannel).whisper('typing', { from: state.me.id }); } catch { /* ignore */ }
    });

    $('message-input')?.addEventListener('paste', (event) => {
        // The pasted value lands after the default action, so measure once
        // the browser has committed it.
        const input = event.target;
        requestAnimationFrame(() => autoGrowTextarea(input));
    });

    $('attach-btn')?.addEventListener('click', () => $('attachment-input')?.click());
    $('attachment-input')?.addEventListener('change', (e) => {
        handleAttachmentSelection(e.target.files);
        e.target.value = '';
    });
    $('attachment-previews')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-attach]');
        if (!btn) return;
        const idx = Number(btn.dataset.removeAttach);
        state.pendingAttachments.splice(idx, 1);
        renderAttachmentPreviews();
        updateSendButton();
    });

    $('emoji-btn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        const picker = $('emoji-picker');
        if (!picker) return;
        picker.hidden = !picker.hidden;
    });
    $('emoji-picker')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-emoji]');
        if (!btn) return;
        const input = $('message-input');
        if (!input) return;
        const emoji = btn.dataset.emoji;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
        const pos = start + emoji.length;
        input.setSelectionRange(pos, pos);
        input.focus();
        autoGrowTextarea(input);
        updateSendButton();
    });

    // Messages clicks (call back, delete menu, lightbox)
    $('messages')?.addEventListener('click', async (event) => {
        const callBack = event.target.closest('[data-call-back]');
        if (callBack) {
            event.stopPropagation();
            const store = window.Alpine?.store('call');
            if (!store || store.status !== 'idle' || !state.active || state.active.type !== 'direct') return;
            store.startCall({
                id: Number(callBack.dataset.callBack),
                name: state.active.name || 'Unknown',
                username: state.active.peer_username || usernameFromSubtitle(state.active.subtitle),
                avatar: state.active.avatar || null,
                conversationId: Number(state.active.id),
            });
            return;
        }
        const menuButton = event.target.closest('[data-message-menu-id]');
        if (menuButton) {
            event.stopPropagation();
            openDeleteDialog(Number(menuButton.dataset.messageMenuId), menuButton);
            return;
        }
        const image = event.target.closest('.msg-image');
        if (image) {
            $('lightbox-image').src = image.dataset.preview || image.src;
            $('lightbox-download').href = image.dataset.download || image.src;
            $('lightbox')?.classList.add('show');
        }
    });

    // Notes
    $('notes-list')?.addEventListener('click', async (event) => {
        const addBtn = event.target.closest('[data-note-action="add"]');
        if (addBtn) { openNoteEditor(state.notes.find((n) => n.is_mine) || null); return; }
        const noteBtn = event.target.closest('[data-note-id]');
        if (!noteBtn) return;
        const note = state.notes.find((n) => String(n.id) === String(noteBtn.dataset.noteId));
        if (!note) return;
        // Clicking a friend's note: open chat with them
        const userId = note.user?.id;
        if (userId) {
            const conv = state.conversations.find((c) => c.type === 'direct' && Number(c.peer_id) === Number(userId));
            if (conv) {
                openConversation(conv.id);
            } else {
                // try to start/open direct if friends
                try {
                    const res = await api(`/api/conversations/direct/${userId}`, { method: 'POST' });
                    if (res?.id) { await loadConversations(); openConversation(res.id); }
                } catch { openNoteViewer(note); }
            }
        } else {
            openNoteViewer(note);
        }
    });
    $('note-save-btn')?.addEventListener('click', saveNote);
    $('note-delete-btn')?.addEventListener('click', deleteNote);

    // Find friends modal
    $('friend-search')?.addEventListener('input', debounce((e) => searchFindFriends(e.target.value.trim()), 250));
    $('friend-results')?.addEventListener('click', (e) => {
        const addBtn = e.target.closest('[data-find-add]');
        const msgBtn = e.target.closest('[data-find-message]');
        try {
            if (addBtn) sendFriendRequestTo(JSON.parse(addBtn.dataset.findAdd));
            else if (msgBtn) handleFindMessageUser(JSON.parse(msgBtn.dataset.findMessage));
        } catch { /* ignore */ }
    });

    // Friends actions
    $('friend-list')?.addEventListener('click', async (e) => {
        const msgBtn = e.target.closest('[data-friend-message]');
        const remBtn = e.target.closest('[data-friend-remove]');
        if (msgBtn) {
            const userId = Number(msgBtn.dataset.friendMessage);
            try {
                let conv = state.conversations.find((c) => c.type === 'direct' && Number(c.peer_id) === userId);
                if (!conv) {
                    const res = await api(`/api/conversations/direct/${userId}`, { method: 'POST' });
                    await loadConversations();
                    conv = state.conversations.find((c) => c.id === res?.id);
                }
                if (conv) { activateSideTab('chats'); openConversation(conv.id); }
            } catch (error) { toast(error.message); }
        }
        if (remBtn) {
            const userId = Number(remBtn.dataset.friendRemove);
            if (!confirm('Remove this friend?')) return;
            try {
                await api(`/api/friends/user/${userId}`, { method: 'DELETE' });
                toast('Friend removed.');
                await Promise.all([loadRequests({ showLoading: false }), loadConversations(), loadNotes()]);
            } catch (error) { toast(error.message); }
        }
    });

    // Requests UI (sub-tabs, retry/empty states, actions, menus)
    $('requests-subtab-received')?.addEventListener('click', () => activateRequestsSubTab('received'));
    $('requests-subtab-sent')?.addEventListener('click', () => activateRequestsSubTab('sent'));
    $('requests-received-retry')?.addEventListener('click', () => loadRequests({ showLoading: true }));
    $('requests-sent-retry')?.addEventListener('click', () => loadRequests({ showLoading: true }));
    $('requests-received-find')?.addEventListener('click', openFindFriendsModal);
    $('requests-sent-find')?.addEventListener('click', openFindFriendsModal);

    const onRequestsClick = async (e) => {
        const toggle = e.target.closest('[data-req-menu-toggle]');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();
            const key = toggle.dataset.reqMenuToggle;
            const menu = Array.from(document.querySelectorAll('.req-menu')).find((m) => m.dataset.reqMenu === key);
            if (!menu) return;
            const open = menu.hidden;
            document.querySelectorAll('.req-menu').forEach((m) => {
                if (m !== menu) {
                    m.hidden = true;
                    m.closest('.req-menu-wrap')?.querySelector('[data-req-menu-toggle]')?.setAttribute('aria-expanded', 'false');
                }
            });
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
            return;
        }

        const actionBtn = e.target.closest('[data-req-action]');
        if (!actionBtn) return;
        e.preventDefault();
        if (actionBtn.disabled || actionBtn.getAttribute('aria-disabled') === 'true') return;
        // close menus before acting
        document.querySelectorAll('.req-menu:not([hidden])').forEach((m) => {
            m.hidden = true;
            m.closest('.req-menu-wrap')?.querySelector('[data-req-menu-toggle]')?.setAttribute('aria-expanded', 'false');
        });
        const action = actionBtn.dataset.reqAction;
        const kind = actionBtn.dataset.kind;
        const id = Number(actionBtn.dataset.id);
        if (action && kind && id) await handleRequestAction(action, kind, id);
    };
    $('requests-panel-received')?.addEventListener('click', onRequestsClick);
    $('requests-panel-sent')?.addEventListener('click', onRequestsClick);

    // Message request composer modal (send request to non-friend)
    $('request-send-btn')?.addEventListener('click', async () => {
        if (state.currentRequest?.mode !== 'compose') return;
        const user = state.currentRequest.user;
        const body = $('request-body')?.value.trim();
        if (!body) { toast('Write a short introduction.'); return; }
        const btn = $('request-send-btn');
        if (btn) btn.disabled = true;
        try {
            await api('/api/message-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: user.id, body }),
            });
            $('request-modal')?.classList.remove('show');
            toast('Message request sent.');
            await Promise.all([loadRequests({ showLoading: false }), loadConversations()]);
        } catch (error) {
            toast(error.message);
        } finally {
            if (btn) btn.disabled = false;
        }
    });

    // Friend request from find-friends note: send friend request directly
    // (when picking a non-friend we open message request composer per requirements; friend requests also exist separately.
    //  Provide a secondary flow: if modal open and we want friend request, we can support via button? The UI shows "Request" label for non-friends and clicking opens message request composer - which matches spec "start a conversation / request" flow. Friend-request endpoint is still available via other interactions if needed; not exposed separately in new simplified UI, but core flows work.)

    // Group modal
    $('group-member-search')?.addEventListener('input', debounce((e) => searchGroupMembers(e.target.value.trim()), 250));
    $('group-members')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-member-id]');
        if (!btn) return;
        const id = Number(btn.dataset.memberId);
        if (state.selectedMembers.has(id)) state.selectedMembers.delete(id); else state.selectedMembers.add(id);
        const selected = state.selectedMembers.has(id);
        btn.textContent = selected ? '✓ Added' : 'Add';
        btn.classList.toggle('primary', selected);
    });
    $('group-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.selectedMembers.size) { toast('Select at least one member.'); return; }
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
            $('group-modal')?.classList.remove('show');
            await loadConversations();
            if (group?.conversation_id) await openConversation(group.conversation_id);
            toast('Group created.');
        } catch (error) { toast(error.message); }
    });

    // Group settings
    $('settings-members')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-remove-member]');
        if (!btn || !state.settingsGroupId) return;
        try {
            await api(`/api/groups/${state.settingsGroupId}/members/${btn.dataset.removeMember}`, { method: 'DELETE' });
            await loadGroupSettings(state.settingsGroupId);
            await loadConversations();
        } catch (error) { toast(error.message); }
    });
    $('add-member-search')?.addEventListener('input', debounce(async (e) => {
        if (!state.settingsGroupId) return;
        const query = e.target.value.trim();
        try {
            const users = await api(`/api/users?q=${encodeURIComponent(query)}`);
            const box = $('add-member-results');
            if (!box) return;
            if (!users.length) { box.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:12.5px">No users found.</div>'; return; }
            box.innerHTML = users.map((u) => `
                <button type="button" data-add-member-user="${u.id}">
                    ${avatarImg(u.avatar, u.name, u.username, 'sm')}
                    <span class="who"><strong>${esc(u.name)}</strong><small>@${esc(u.username)}</small></span>
                </button>`).join('');
        } catch (error) { toast(error.message); }
    }, 250));
    $('add-member-results')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-add-member-user]');
        if (!btn || !state.settingsGroupId) return;
        try {
            await api(`/api/groups/${state.settingsGroupId}/members`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: Number(btn.dataset.addMemberUser) }),
            });
            if ($('add-member-search')) $('add-member-search').value = '';
            if ($('add-member-results')) $('add-member-results').innerHTML = '';
            await loadGroupSettings(state.settingsGroupId);
            await loadConversations();
        } catch (error) { toast(error.message); }
    });
    $('leave-group-btn')?.addEventListener('click', async () => {
        if (!state.activeGroup || !confirm('Leave this group?')) return;
        try {
            await api(`/api/groups/${state.activeGroup.id}/leave`, { method: 'POST' });
            const conversationId = state.active?.id;
            $('settings-modal')?.classList.remove('show');
            if (conversationId) handleAccessRevoked(conversationId);
            await loadConversations();
        } catch (error) { toast(error.message); }
    });
    $('delete-group-btn')?.addEventListener('click', async () => {
        if (!state.activeGroup || !confirm('Delete this group for everyone?')) return;
        try {
            await api(`/api/groups/${state.activeGroup.id}`, { method: 'DELETE' });
            const conversationId = state.active?.id;
            $('settings-modal')?.classList.remove('show');
            if (conversationId) handleAccessRevoked(conversationId);
            await loadConversations();
        } catch (error) { toast(error.message); }
    });

    // Notifications
    $('notification-btn')?.addEventListener('click', async () => {
        $('notifications-modal')?.classList.add('show');
        await loadNotifications();
    });
    $('notifications-read-btn')?.addEventListener('click', async () => {
        const previousCount = state.notificationCount;

        // Optimistic: close immediately and clear the bell badge.
        state.notificationCount = 0;
        updateNotificationBadge();
        $('notifications-modal')?.classList.remove('show');

        try {
            const result = await api('/api/notifications/read-all', { method: 'POST' });
            state.notificationCount = result.unread_count ?? 0;
            updateNotificationBadge();
            document.querySelectorAll('#notifications-list .notification-row').forEach((row) => markNotificationRowRead(row));
            toast('All notifications marked as read.');
        } catch (error) {
            state.notificationCount = previousCount;
            updateNotificationBadge();
            toast(error.message);
            await loadNotifications();
        }
    });
    $('notifications-list')?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-notification-id]');
        if (!row) return;
        markNotificationRead(row.dataset.notificationId);
        if (row.dataset.notificationType === 'missed_call' && row.dataset.conversationId) {
            $('notifications-modal')?.classList.remove('show');
            activateSideTab('chats');
            openConversation(Number(row.dataset.conversationId));
        }
    });
    $('notifications-list')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('[data-notification-id]');
        if (!row) return;
        event.preventDefault();
        markNotificationRead(row.dataset.notificationId);
        if (row.dataset.notificationType === 'missed_call' && row.dataset.conversationId) {
            $('notifications-modal')?.classList.remove('show');
            activateSideTab('chats');
            openConversation(Number(row.dataset.conversationId));
        }
    });

    // Delete message dialog
    $('delete-everyone-btn')?.addEventListener('click', () => confirmDelete('everyone'));
    $('delete-for-me-btn')?.addEventListener('click', () => confirmDelete('me'));
    $('delete-cancel-btn')?.addEventListener('click', () => closeDeleteDialog());
    $('delete-message-modal')?.addEventListener('click', (event) => {
        if (event.target === $('delete-message-modal')) closeDeleteDialog();
    });
    document.addEventListener('keydown', trapDeleteDialogFocus);

    // Modal close helpers
    document.querySelectorAll('[data-close]').forEach((button) => {
        button.addEventListener('click', () => $(button.dataset.close)?.classList.remove('show'));
    });
    document.querySelectorAll('.backdrop').forEach((backdrop) => {
        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) backdrop.classList.remove('show');
        });
    });
}

/* ================================================================
 * User channel & connection events
 * ================================================================ */
window.Echo.private(`user.${state.me.id}`)
    .listen('.UnreadCountsUpdated', (event) => {
        applyUnreadCounts(event.counts);
    })
    .listen('.ConversationsUpdated', () => {
        loadConversations();
    })
    .listen('.PresenceUpdated', (event) => {
        if (!event.user) return;
        const id = Number(event.user.id);
        state.presence.set(id, event.user);
        if (event.online) state.online.set(id, event.user); else state.online.delete(id);
        updatePresenceUI();
    })
    .listen('.NotificationCreated', async (event) => {
        state.notificationCount = event.unread_count ?? (state.notificationCount + 1);
        updateNotificationBadge();
        toast(notificationText(event));
        if ($('notifications-modal')?.classList.contains('show')) loadNotifications();
        await loadRequests({ showLoading: false });
        loadNotes();
        loadConversations();
        if (state.sideTab === 'requests' && state.requestsUI.subTab === 'received') {
            markRequestsSeen();
        }
    })
    .listen('.NotificationRead', (event) => {
        if (event.unread_count != null) {
            state.notificationCount = event.unread_count;
            updateNotificationBadge();
        }

        const listOpen = $('notifications-modal')?.classList.contains('show');
        if (event.all) {
            if (listOpen) loadNotifications();
            return;
        }

        if (event.notification_id != null) {
            const row = document.querySelector(`[data-notification-id="${event.notification_id}"]`);
            if (row) markNotificationRowRead(row);
        }
    });

window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    const offline = states.current !== 'connected';
    $('offline-banner')?.classList.toggle('show', offline);
    if (states.current === 'connected' && states.previous !== 'connected') {
        loadConversations();
        loadPresence();
    }
});

/* ================================================================
 * Bootstrap
 * ================================================================ */
function init() {
    renderNotesSkeleton();
    renderChatListSkeleton();
    renderEmojiPicker();
    autoGrowTextarea($('message-input'));
    updateSendButton();
    updateNotificationBadge();
    bindEvents();
    loadPresence();
    loadConversations();
    loadRequests({ showLoading: true });
    loadNotes();
    loadNotifications();
    activateSideTab('chats');

    // Keep composer usable when viewport changes (mobile keyboard)
    window.visualViewport?.addEventListener('resize', () => {
        if (state.active && isNearBottom(120)) scrollToLatestRow();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
