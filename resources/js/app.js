import './echo';

/*
 * THE PIECES:
 *
 * 1. Presence channel `presence-online`  → the sidebar (who is online)
 * 2. Private channel `chat.{a}.{b}`      → one 1:1 conversation
 *    (both users subscribe to the SAME channel; ids are pair-sorted)
 * 3. Server events `.MessageSent`        → actual chat messages
 * 4. Client events (whisper) `typing`    → typing indicator
 *
 * Whispers are the interesting bit: they travel browser → Reverb →
 * browser WITHOUT touching Laravel at all. No controller, no event
 * class, no HTTP request. That's why typing indicators feel instant
 * and cost the server nothing. They only work on private/presence
 * channels (anyone could otherwise send arbitrary events).
 */

const state = {
    online: [],        // [{id, name}]
    active: null,      // {id, name} of the user being chatted with
    activeChannel: null,
    typingTimer: null,
    lastWhisperSent: 0,
};

const $ = (id) => document.getElementById(id);
const onlineList = $('online-list');

function csrf() {
    return document.querySelector('meta[name="csrf-token"]').content;
}

/* ================================================================
 * Sidebar — presence channel
 * ================================================================ */

// Alice logged in twice would appear twice; dedupe by id.
function renderSidebar() {
    const seen = new Map();
    state.online.forEach((u) => seen.set(u.id, u));
    const users = [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));

    onlineList.innerHTML = '';

    const me = document.createElement('li');
    me.className = 'self';
    me.innerHTML = `<span class="dot"></span> ${window.myName} <span style="margin-left:auto;font-size:11px;color:#64748b">you</span>`;
    onlineList.appendChild(me);

    if (!users.filter((u) => u.id !== window.userId).length) {
        const li = document.createElement('li');
        li.className = 'muted';
        li.textContent = 'nobody else online — open another browser as a different user';
        onlineList.appendChild(li);
        return;
    }

    users.forEach((u) => {
        if (u.id === window.userId) return;
        const li = document.createElement('li');
        li.dataset.id = u.id;
        li.innerHTML = `<span class="dot"></span> ${u.name}`;
        if (state.active && state.active.id === u.id) li.classList.add('active');
        li.addEventListener('click', () => openConversation(u));
        onlineList.appendChild(li);
    });
}

// NOTE: the presence CHANNEL on the wire is `presence-online`, but you
// pass only the bare name to Echo — it adds the `presence-` prefix for
// you. Writing join('presence-online') would create the nonsensical
// wire channel `presence-presence-online`.
window.Echo.join('online')
    .here((users) => { state.online = users; renderSidebar(); })
    .joining((user) => {
        state.online.push(user);
        renderSidebar();
        console.log('[presence] joined:', user.name);
    })
    .leaving((user) => {
        state.online = state.online.filter((u) => u.id !== user.id);
        renderSidebar();
        console.log('[presence] left:', user.name);
    });

/* ================================================================
 * Conversation — private channel `chat.{minId}.{maxId}`
 * ================================================================ */

function pairChannel(me, other) {
    const ids = [me, other].sort((a, b) => a - b);
    return `chat.${ids[0]}.${ids[1]}`;
}

async function openConversation(user) {
    if (state.active?.id === user.id) return;

    // Leave the previous conversation's channel so we don't keep
    // receiving its messages (and its typing whispers).
    if (state.activeChannel) {
        window.Echo.leave(state.activeChannel);
        state.activeChannel = null;
    }

    state.active = user;
    $('empty').style.display = 'none';
    $('conv').classList.add('open');
    $('conv-name').textContent = user.name;
    $('msgs').innerHTML = '';
    hideTyping();
    renderSidebar();

    // Backfill history over HTTP, then subscribe for live traffic.
    const history = await fetch(`/messages/${user.id}`).then((r) => r.json());
    if (state.active?.id !== user.id) return; // user switched meanwhile
    history.forEach(addBubble);

    const channelName = pairChannel(window.userId, user.id);
    state.activeChannel = channelName;

    window.Echo.private(channelName)
        .listen('.MessageSent', (e) => {
            addBubble(e);
            hideTyping();
        })
        // Typing indicator: a CLIENT event, browser-to-browser only.
        .listenForWhisper('typing', (e) => {
            if (e.from !== user.id) return;
            showTyping();
            clearTimeout(state.typingTimer);
            state.typingTimer = setTimeout(hideTyping, 2000);
        });
}

function addBubble(payload) {
    // Only messages of THIS conversation; switching channels can race.
    if (!state.active) return;
    const mine = payload.from === window.userId;
    if (!mine && payload.to !== window.userId) return;

    const div = document.createElement('div');
    div.className = 'msg ' + (mine ? 'mine' : 'theirs');
    div.textContent = payload.body;
    $('msgs').appendChild(div);
    $('msgs').scrollTop = $('msgs').scrollHeight;
}

/* ================================================================
 * Composing: send + typing whispers
 * ================================================================ */

$('compose').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!state.active) return;
    const input = $('compose-input');
    const body = input.value.trim();
    if (!body) return;
    input.value = '';

    await fetch('/messages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ to: state.active.id, body }),
    });
    // Note: we do NOT add the bubble here — our own `.MessageSent`
    // listener receives OUR message back from Reverb and renders it.
    // One code path for both sides of the conversation.
});

$('compose-input').addEventListener('input', () => {
    if (!state.active || !state.activeChannel) return;

    // Throttle: whisper at most every 800ms so fast typing doesn't spam.
    const now = Date.now();
    if (now - state.lastWhisperSent < 800) return;
    state.lastWhisperSent = now;

    window.Echo.private(state.activeChannel)
        .whisper('typing', { from: window.userId });
});

function showTyping() {
    $('typing-name').textContent = state.active?.name ?? '';
    $('typing').classList.add('show');
}

function hideTyping() {
    $('typing').classList.remove('show');
}
