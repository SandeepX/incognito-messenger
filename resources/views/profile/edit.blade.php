@extends('layouts.auth')

@section('title', 'Settings — Incognito Messenger')
@section('body-class', 'settings-page')
@section('shell-class', 'wide')
@section('card-class', 'settings-card')

@section('brand', '')

@php
    $profile = $user->profile;
    $initial = [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'email_verified' => $user->hasVerifiedEmail(),
        'status' => $profile?->status ?? '',
        'bio' => $profile?->bio ?? '',
        'avatar' => $user->avatarUrl(),
        'theme_preference' => $user->themePreference(),
        'message_sounds' => $user->messageSoundsEnabled(),
        'message_sounds_volume' => $user->messageSoundsVolume(),
        'desktop_notifications' => $user->desktopNotificationsEnabled(),
        'show_active_status' => $user->showActiveStatus(),
        'read_receipts' => $user->readReceiptsEnabled(),
        'message_request_policy' => $user->messageRequestPolicy(),
        'notes_visibility' => $user->notesVisibility(),
        'allow_calls' => $user->callPolicy(),
        'call_ringtone' => $user->callRingtoneEnabled(),
    ];
@endphp

@push('styles')
<style>
    body.settings-page { align-items: flex-start; padding: 20px; }
    .shell.wide { max-width: 860px; }
    .settings-card { padding: 0; overflow: hidden; position: relative; }
    .settings-header { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: var(--surface); z-index: 6; }
    .settings-header h1 { font-size: 18px; margin: 0; }
    .icon-btn { width: 40px; height: 40px; min-width: 40px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface-2); color: var(--text); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; transition: border-color .15s ease, background .15s ease; }
    .icon-btn:hover { border-color: var(--accent); }
    .settings-layout { display: flex; min-height: 560px; }
    .settings-nav { width: 210px; border-right: 1px solid var(--line); padding: 12px; background: var(--surface); flex-shrink: 0; }
    .settings-tabs { display: none; padding: 10px 12px; border-bottom: 1px solid var(--line); overflow-x: auto; gap: 8px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .settings-tabs::-webkit-scrollbar { display: none; }
    .nav-item, .tab-item { width: 100%; text-align: left; border: 1px solid transparent; background: transparent; color: var(--muted); padding: 10px 12px; min-height: 40px; border-radius: 10px; cursor: pointer; font: inherit; font-size: 14px; font-weight: 600; transition: background .15s ease, color .15s ease, border-color .15s ease; }
    .tab-item { width: auto; white-space: nowrap; flex: 0 0 auto; }
    .nav-item:hover, .tab-item:hover { color: var(--text); background: var(--surface-2); }
    .nav-item.active, .tab-item.active { color: var(--text); background: var(--surface-2); border-color: var(--line); }
    .settings-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .settings-content { flex: 1; padding: 18px 20px 110px; }
    .section { display: none; }
    .section.active { display: block; }
    .section h2 { font-size: 16px; margin-bottom: 4px; }
    .section-desc { color: var(--muted); font-size: 13px; margin-bottom: 18px; }
    .field { margin-bottom: 16px; }
    .field-label { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px; display: block; }
    .field-help { font-size: 12px; color: var(--hint); margin-top: 4px; }
    .field-error { font-size: 12px; color: var(--error-text); margin-top: 4px; min-height: 16px; }
    .field-success { font-size: 12px; color: #22c55e; margin-top: 4px; min-height: 16px; }
    html[data-theme="light"] .field-success { color: #15803d; }
    .counter { font-size: 12px; color: var(--hint); text-align: right; margin-top: 4px; }
    .counter.over { color: var(--danger); }
    .input-invalid { border-color: var(--danger) !important; }
    .avatar-block { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
    .avatar-picker { flex: none; }
    .avatar-button { position: relative; width: 112px; height: 112px; border-radius: 50%; padding: 0; border: 2px solid var(--line); background: var(--surface-2); cursor: pointer; overflow: visible; flex-shrink: 0; display: block; }
    .avatar-button:hover { border-color: var(--accent); }
    .avatar-button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
    .avatar-clip { position: absolute; inset: 0; width: 100%; height: 100%; border-radius: 50%; overflow: hidden; display: block; background: var(--surface-2); }
    .avatar-clip img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; }
    .camera-badge { position: absolute; right: -2px; bottom: -2px; width: 32px; height: 32px; border-radius: 50%; background: var(--accent); color: #fff; border: 2px solid var(--surface); display: flex; align-items: center; justify-content: center; font-size: 14px; line-height: 1; z-index: 2; pointer-events: none; }
    .avatar-meta { display: flex; flex-direction: column; align-items: flex-start; justify-content: center; min-width: 0; gap: 8px; }
    .avatar-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    @media (max-width: 520px) {
        .avatar-block { flex-direction: column; align-items: flex-start; }
        .avatar-meta { width: 100%; }
    }
    .inline-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
    .btn-sm { min-height: 40px; padding: 8px 12px; font-size: 13px; width: auto; margin-top: 0; }
    .badge-state { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .badge-ok { background: var(--success-bg); color: var(--success-text); }
    .badge-warn { background: rgba(245,158,11,.15); color: #f59e0b; }
    html[data-theme="light"] .badge-warn { color: #b45309; background: #fef3c7; }
    .segmented { display: inline-flex; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: var(--surface-2); }
    .segmented button { border: 0; border-right: 1px solid var(--line); background: transparent; color: var(--muted); min-height: 40px; padding: 8px 16px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s ease, color .15s ease; }
    .segmented button:last-child { border-right: 0; }
    .segmented button.active { background: var(--accent); color: #fff; }
    .switch { position: relative; width: 48px; min-width: 48px; height: 28px; border-radius: 999px; border: 1px solid var(--line); background: #475569; cursor: pointer; transition: background .15s ease, border-color .15s ease; padding: 0; flex-shrink: 0; }
    .switch::after { content: ""; position: absolute; top: 2px; left: 2px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: transform .15s ease; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
    .switch[aria-checked="true"] { background: var(--accent); border-color: var(--accent); }
    .switch[aria-checked="true"]::after { transform: translateX(20px); }
    .setting-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 14px 0; border-bottom: 1px solid var(--line); }
    .setting-row:last-child { border-bottom: 0; }
    .setting-meta { flex: 1; min-width: 0; }
    .range-row { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
    input[type=range] { width: 100%; accent-color: var(--accent); height: 28px; }
    .progress-wrap { height: 8px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--line); overflow: hidden; margin-top: 8px; max-width: 320px; }
    .progress-bar { height: 100%; width: 0%; background: var(--accent); transition: width .15s ease; }
    .save-bar { position: sticky; bottom: 0; display: none; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 18px; border-top: 1px solid var(--line); background: rgba(15,23,42,.94); backdrop-filter: blur(8px); z-index: 7; }
    html[data-theme="light"] .save-bar { background: rgba(255,255,255,.96); }
    .save-bar.show { display: flex; }
    .save-actions { display: flex; gap: 8px; }
    .toast-stack { position: fixed; right: 16px; bottom: 16px; display: flex; flex-direction: column; gap: 8px; z-index: 60; pointer-events: none; }
    .toast { pointer-events: auto; min-width: 220px; max-width: 340px; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); color: var(--text); box-shadow: 0 10px 30px rgba(0,0,0,.25); font-size: 13px; transition: opacity .15s ease, transform .15s ease; }
    .toast.error { border-color: var(--danger); }
    .blocked-list, .session-list { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
    .list-card { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface-2); }
    .list-main { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .list-main img, .mini-fallback { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
    .mini-fallback { display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
    .list-info { min-width: 0; }
    .list-info strong { display: block; font-size: 14px; }
    .list-info span, .list-info small { display: block; color: var(--muted); font-size: 12px; word-break: break-word; }
    .empty-state { padding: 14px; border: 1px dashed var(--line); border-radius: 12px; color: var(--muted); font-size: 13px; text-align: center; }
    .danger-zone { border: 1px solid rgba(239,68,68,.4); border-radius: 14px; padding: 14px; background: rgba(239,68,68,.06); margin-top: 18px; }
    .danger-zone h3 { color: var(--danger); font-size: 15px; margin-bottom: 6px; }
    .divider { border: 0; border-top: 1px solid var(--line); margin: 18px 0; }
    .modal-backdrop { position: fixed; inset: 0; background: rgba(2,6,23,.6); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
    .modal-backdrop.show { display: flex; }
    .modal { width: 100%; max-width: 540px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 16px; max-height: 95vh; overflow: auto; }
    .modal h3 { margin-bottom: 8px; font-size: 16px; }
    .crop-stage { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: start; }
    .crop-area { position: relative; width: 100%; max-width: 320px; aspect-ratio: 1 / 1; background: #0b1220; border-radius: 12px; overflow: hidden; touch-action: none; margin: 0 auto; }
    .crop-area img { position: absolute; left: 50%; top: 50%; transform-origin: center center; user-select: none; -webkit-user-drag: none; cursor: grab; max-width: none; }
    .crop-mask { position: absolute; inset: 0; pointer-events: none; box-shadow: 0 0 0 9999px rgba(0,0,0,.45); border: 2px solid rgba(255,255,255,.85); }
    .crop-mask.circle { border-radius: 50%; }
    .crop-previews { display: flex; flex-direction: column; gap: 12px; align-items: center; }
    .preview-box { text-align: center; font-size: 12px; color: var(--muted); }
    .preview-square, .preview-circle { width: 72px; height: 72px; background: var(--surface-2); border: 1px solid var(--line); overflow: hidden; }
    .preview-square { border-radius: 12px; }
    .preview-circle { border-radius: 50%; }
    .preview-square img, .preview-circle img { width: 100%; height: 100%; object-fit: cover; }
    .strength { height: 8px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--line); overflow: hidden; margin-top: 6px; }
    .strength span { display: block; height: 100%; width: 0%; transition: width .15s ease, background .15s ease; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    @media (max-width: 767px) {
        body.settings-page { padding: 0; }
        .shell.wide { max-width: none; }
        .settings-card { border-radius: 0; border-left: 0; border-right: 0; min-height: 100vh; }
        .settings-layout { flex-direction: column; }
        .settings-nav { display: none; }
        .settings-tabs { display: flex; }
        .settings-content { padding: 14px 14px 120px; }
        .crop-stage { grid-template-columns: 1fr; }
        .crop-previews { flex-direction: row; justify-content: center; }
    }
</style>
@endpush

@section('content')
<div class="settings-header">
    <a class="icon-btn" href="{{ route('home') }}" aria-label="Back to messages" title="Back to messages">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </a>
    <h1>Settings</h1>
</div>

<div class="settings-layout" id="settings-app"
     data-initial='@json($initial)'
     data-sessions='@json($sessions)'
     data-blocked='@json($blockedUsers)'
     data-avatar-route="{{ route('profile.avatar') }}"
     data-avatar-remove-route="{{ route('profile.avatar.remove') }}"
     data-password-route="{{ route('profile.password.update') }}"
     data-delete-route="{{ route('profile.destroy') }}"
     data-verify-route="{{ route('verification.send') }}"
     data-login-route="{{ route('login') }}">
    <nav class="settings-nav" aria-label="Settings sections">
        <button type="button" class="nav-item" data-section="profile">Profile</button>
        <button type="button" class="nav-item" data-section="appearance">Appearance</button>
        <button type="button" class="nav-item" data-section="notifications">Notifications</button>
        <button type="button" class="nav-item" data-section="calls">Calls</button>
        <button type="button" class="nav-item" data-section="privacy">Privacy</button>
        <button type="button" class="nav-item" data-section="account">Account</button>
    </nav>

    <div class="settings-main">
        <div class="settings-tabs" role="tablist" aria-label="Settings sections">
            <button type="button" class="tab-item" role="tab" data-section="profile">Profile</button>
            <button type="button" class="tab-item" role="tab" data-section="appearance">Appearance</button>
            <button type="button" class="tab-item" role="tab" data-section="notifications">Notifications</button>
            <button type="button" class="tab-item" role="tab" data-section="calls">Calls</button>
            <button type="button" class="tab-item" role="tab" data-section="privacy">Privacy</button>
            <button type="button" class="tab-item" role="tab" data-section="account">Account</button>
        </div>

        <div class="settings-content">
            <section id="section-profile" class="section">
                <h2>Profile</h2>
                <p class="section-desc">Manage your photo and personal details.</p>

                <div class="avatar-block">
                    <div class="avatar-picker">
                        <button type="button" id="avatar-trigger" class="avatar-button" aria-label="Change profile photo">
                            <span class="avatar-clip" aria-hidden="true">
                                <img id="avatar-image" alt="" decoding="async">
                            </span>
                            <span class="camera-badge" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            </span>
                        </button>
                    </div>
                    <div class="avatar-meta">
                        <div class="avatar-actions">
                            <button type="button" class="btn btn-secondary btn-sm" id="avatar-change-btn">Change photo</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="avatar-remove-btn">Remove photo</button>
                        </div>
                        <div class="field-help">JPEG, PNG, GIF or WebP. Max 10 MB.</div>
                        <div id="avatar-error" class="field-error" role="alert"></div>
                        <div id="avatar-progress" class="progress-wrap" hidden aria-hidden="true"><div id="avatar-progress-bar" class="progress-bar"></div></div>
                    </div>
                    <input id="avatar-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                </div>

                <form id="profile-form" novalidate>
                    <div class="field">
                        <label class="field-label" for="name">Full name</label>
                        <input id="name" name="name" type="text" maxlength="255" autocomplete="name" required>
                        <div class="field-error" data-error-for="name"></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="username">Username</label>
                        <input id="username" name="username" type="text" minlength="3" maxlength="30" autocomplete="username" required>
                        <div class="field-help">3–30 characters. Letters, numbers, dashes and underscores.</div>
                        <div id="username-status" class="field-success" aria-live="polite"></div>
                        <div class="field-error" data-error-for="username"></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="email">Email</label>
                        <input id="email" name="email" type="email" autocomplete="email" required>
                        <div class="inline-actions" style="align-items:center; margin-top:6px;">
                            <span id="email-verify-badge" class="badge-state"></span>
                            <button type="button" id="verify-email-btn" class="btn btn-secondary btn-sm" hidden>Verify</button>
                        </div>
                        <div id="email-verify-status" class="field-success" aria-live="polite"></div>
                        <div class="field-error" data-error-for="email"></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="status">Status message</label>
                        <input id="status" name="status" type="text" maxlength="60" placeholder="What's on your mind?">
                        <div class="counter"><span id="status-count">0</span>/60</div>
                        <div class="field-error" data-error-for="status"></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="bio">Bio</label>
                        <textarea id="bio" name="bio" rows="3" maxlength="160" placeholder="A short bio about you"></textarea>
                        <div class="counter"><span id="bio-count">0</span>/160</div>
                        <div class="field-error" data-error-for="bio"></div>
                    </div>
                </form>
            </section>

            <section id="section-appearance" class="section">
                <h2>Appearance</h2>
                <p class="section-desc">Choose how Incognito Messenger looks.</p>
                <div class="field">
                    <div class="field-label" id="theme-label">Theme</div>
                    <div class="field-help" style="margin-bottom:10px;">Applied instantly for your account.</div>
                    <div class="segmented" role="radiogroup" aria-labelledby="theme-label">
                        <button type="button" class="theme-option" data-theme="system" role="radio" aria-checked="false">System</button>
                        <button type="button" class="theme-option" data-theme="light" role="radio" aria-checked="false">Light</button>
                        <button type="button" class="theme-option" data-theme="dark" role="radio" aria-checked="false">Dark</button>
                    </div>
                </div>
            </section>

            <section id="section-notifications" class="section">
                <h2>Notifications</h2>
                <p class="section-desc">Control sounds and desktop alerts.</p>

                <div class="setting-row">
                    <div class="setting-meta">
                        <div class="field-label" id="label-message-sounds">Message sounds</div>
                        <div class="field-help">Play a sound when new messages arrive.</div>
                        <div id="sound-controls" style="margin-top:10px;">
                            <label for="message-volume" class="field-help" style="margin-top:0;">Volume: <span id="message-volume-value">100</span>%</label>
                            <div class="range-row">
                                <input id="message-volume" type="range" min="0" max="100" step="1" aria-label="Message sound volume">
                                <button type="button" id="test-sound-btn" class="btn btn-secondary btn-sm">Test sound</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="message-sounds-switch" class="switch" role="switch" aria-checked="false" aria-labelledby="label-message-sounds"></button>
                </div>

                <div class="setting-row">
                    <div class="setting-meta">
                        <div class="field-label" id="label-desktop-notifications">Desktop notifications</div>
                        <div class="field-help">Get browser notifications for activity while Incognito is open.</div>
                        <div id="desktop-notification-state" class="field-help" aria-live="polite" style="margin-top:6px;"></div>
                    </div>
                    <button type="button" id="desktop-notifications-switch" class="switch" role="switch" aria-checked="false" aria-labelledby="label-desktop-notifications"></button>
                </div>
            </section>

            <section id="section-calls" class="section">
                <h2>Calls</h2>
                <p class="section-desc">Control who can ring you and check your audio devices.</p>

                <div class="field">
                    <label class="field-label" for="allow-calls">Allow audio calls from</label>
                    <select id="allow-calls" class="save-tracked">
                        <option value="friends">Friends</option>
                        <option value="nobody">Nobody</option>
                    </select>
                    <div class="field-help">Calls are always 1-to-1 and only between accepted friends.</div>
                </div>

                <div class="setting-row">
                    <div class="setting-meta">
                        <div class="field-label" id="label-call-ringtone">Call ringtone</div>
                        <div class="field-help">Play a ringtone for incoming calls. Volume follows your message sound volume.</div>
                    </div>
                    <button type="button" id="call-ringtone-switch" class="switch" role="switch" aria-checked="true" aria-labelledby="label-call-ringtone"></button>
                </div>

                <hr class="divider">

                <div class="field">
                    <div class="field-label">Microphone</div>
                    <div class="field-help">Choose the microphone used for calls. The test is local only; nothing is uploaded.</div>
                    <div class="inline-actions">
                        <select id="call-mic-select" aria-label="Microphone" style="max-width:320px;"></select>
                        <button type="button" id="test-mic-btn" class="btn btn-secondary btn-sm">Test microphone</button>
                    </div>
                    <div class="progress-wrap" aria-hidden="true"><div id="mic-level-bar" class="progress-bar"></div></div>
                    <div id="mic-test-status" class="field-help" role="status" aria-live="polite"></div>
                </div>

                <div class="field">
                    <div class="field-label">Speaker</div>
                    <div class="field-help">Choose the audio output for calls. Availability depends on your browser.</div>
                    <div class="inline-actions">
                        <select id="call-speaker-select" aria-label="Speaker" style="max-width:320px;"></select>
                    </div>
                    <div id="speaker-device-help" class="field-help"></div>
                </div>

                <div id="call-https-warning" class="field-help" style="display:none;color:var(--danger);">
                    Audio calls require HTTPS (localhost is allowed). Open this site over HTTPS to use the microphone.
                </div>
            </section>

            <section id="section-privacy" class="section">
                <h2>Privacy</h2>
                <p class="section-desc">Choose who can interact with you and what others can see.</p>

                <div class="setting-row">
                    <div class="setting-meta">
                        <div class="field-label" id="label-show-active-status">Show active status</div>
                        <div class="field-help">Let others see when you were last active.</div>
                    </div>
                    <button type="button" id="show-active-status" class="switch save-tracked" role="switch" aria-checked="false" aria-labelledby="label-show-active-status"></button>
                </div>

                <div class="setting-row">
                    <div class="setting-meta">
                        <div class="field-label" id="label-read-receipts">Read receipts</div>
                        <div class="field-help">When off, your “Seen” ticks are not sent and you do not see others’.</div>
                    </div>
                    <button type="button" id="read-receipts" class="switch save-tracked" role="switch" aria-checked="false" aria-labelledby="label-read-receipts"></button>
                </div>

                <div class="field" style="margin-top:16px;">
                    <label class="field-label" for="message-request-policy">Who can send me message requests</label>
                    <select id="message-request-policy" class="save-tracked">
                        <option value="everyone">Everyone</option>
                        <option value="friends_of_friends">Friends of friends</option>
                        <option value="nobody">Nobody</option>
                    </select>
                </div>

                <div class="field">
                    <label class="field-label" for="notes-visibility">Who can see my notes</label>
                    <select id="notes-visibility" class="save-tracked">
                        <option value="friends">Friends</option>
                        <option value="nobody">Nobody</option>
                    </select>
                </div>

                <div class="field" style="margin-top:18px;">
                    <div class="field-label">Blocked users</div>
                    <div class="field-help">People you block can’t contact you or see your activity.</div>
                    <div id="blocked-users-list" class="blocked-list" aria-live="polite"></div>
                </div>
            </section>

            <section id="section-account" class="section">
                <h2>Account</h2>
                <p class="section-desc">Manage your password, sessions, and account.</p>

                <div class="field" style="padding-bottom:18px; border-bottom:1px solid var(--line);">
                    <h3 style="font-size:15px; margin-bottom:8px;">Change password</h3>
                    <form id="password-form" novalidate>
                        <div class="field">
                            <label class="field-label" for="current_password">Current password</label>
                            <input id="current_password" name="current_password" type="password" autocomplete="current-password">
                            <div class="field-error" data-error-for="current_password"></div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="password">New password</label>
                            <input id="password" name="password" type="password" autocomplete="new-password">
                            <div class="strength" aria-hidden="true"><span id="password-strength-bar"></span></div>
                            <div id="password-strength-text" class="field-help" aria-live="polite"></div>
                            <div class="field-error" data-error-for="password"></div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="password_confirmation">Confirm new password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                            <div class="field-error" data-error-for="password_confirmation"></div>
                        </div>
                        <button type="submit" id="password-submit" class="btn btn-secondary btn-sm">Update password</button>
                    </form>
                </div>

                <div class="field" style="padding:18px 0; border-bottom:1px solid var(--line);">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:8px;">
                        <h3 style="font-size:15px;">Active sessions</h3>
                        <button type="button" id="logout-others-btn" class="btn btn-secondary btn-sm">Log out of all other devices</button>
                    </div>
                    <div class="field-help">See where you’re signed in and remove sessions you don’t recognize.</div>
                    <div id="sessions-list" class="session-list" aria-live="polite"></div>
                </div>

                <div class="danger-zone">
                    <h3>Danger zone</h3>
                    <p class="field-help" style="color:var(--text); margin-bottom:10px;">Deleting your account is permanent. Your profile, messages, and access will be removed and cannot be recovered.</p>
                    <form id="delete-form" novalidate>
                        <div class="field">
                            <label class="field-label" for="delete_password">Password</label>
                            <input id="delete_password" name="password" type="password" autocomplete="current-password">
                            <div class="field-error" data-error-for="delete_password"></div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="delete_confirmation">Type DELETE to confirm</label>
                            <input id="delete_confirmation" name="confirmation" type="text" placeholder="DELETE" autocomplete="off">
                            <div class="field-error" data-error-for="delete_confirmation"></div>
                        </div>
                        <button type="submit" id="delete-submit" class="btn btn-danger btn-sm">Delete account</button>
                    </form>
                </div>
            </section>
        </div>

        <div id="save-bar" class="save-bar" role="status" aria-live="polite">
            <span>Unsaved changes</span>
            <div class="save-actions">
                <button type="button" id="discard-btn" class="btn btn-secondary btn-sm">Discard</button>
                <button type="button" id="save-btn" class="btn btn-primary btn-sm" style="margin-top:0; width:auto;">Save</button>
            </div>
        </div>
    </div>
</div>

<div id="crop-modal" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="crop-title">
        <h3 id="crop-title">Crop profile photo</h3>
        <p class="field-help" style="margin-bottom:10px;">Drag to position. Use the slider to zoom. The photo will be saved as a square.</p>
        <div class="crop-stage">
            <div>
                <div class="crop-area" id="crop-area">
                    <img id="crop-image" alt="">
                    <div id="crop-mask" class="crop-mask circle" aria-hidden="true"></div>
                </div>
                <div class="range-row" style="margin-top:12px;">
                    <label for="crop-zoom" class="sr-only">Zoom</label>
                    <input id="crop-zoom" type="range" min="1" max="3" step="0.01" value="1" aria-label="Zoom">
                </div>
                <div id="crop-error" class="field-error" role="alert"></div>
            </div>
            <div class="crop-previews">
                <div class="preview-box"><div class="preview-circle"><img id="preview-circle" alt=""></div><div>Circle preview</div></div>
                <div class="preview-box"><div class="preview-square"><img id="preview-square" alt=""></div><div>Square preview</div></div>
            </div>
        </div>
        <div class="inline-actions" style="justify-content:flex-end; margin-top:14px;">
            <button type="button" id="crop-cancel" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="button" id="crop-confirm" class="btn btn-primary btn-sm" style="margin-top:0; width:auto;">Upload photo</button>
        </div>
    </div>
</div>

<div id="toast-stack" class="toast-stack" aria-live="polite" aria-atomic="true"></div>
@endsection

@push('scripts')
<script>
(() => {
    const app = document.getElementById('settings-app');
    if (!app) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const routes = {
        avatar: app.dataset.avatarRoute,
        avatarRemove: app.dataset.avatarRemoveRoute,
        password: app.dataset.passwordRoute,
        deleteAccount: app.dataset.deleteRoute,
        verify: app.dataset.verifyRoute,
        login: app.dataset.loginRoute,
        profileSave: '/api/settings/profile',
        usernameCheck: '/api/settings/username/check',
        appearance: '/api/settings/appearance',
        notifications: '/api/settings/notifications',
        blocked: '/api/settings/blocked-users',
        sessions: '/api/settings/sessions',
        logoutOthers: '/api/settings/sessions/others',
    };
    const userId = @json($user->id);

    const initial = JSON.parse(app.dataset.initial || '{}');
    const state = {
        saved: { ...initial },
        saving: false,
        usernameOk: true,
        blocked: JSON.parse(app.dataset.blocked || '[]'),
        sessions: JSON.parse(app.dataset.sessions || '[]'),
        crop: null,
        micTest: null,
    };

    const sections = ['profile','appearance','notifications','calls','privacy','account'];
    const saveFields = ['name','username','email','status','bio','show_active_status','read_receipts','message_request_policy','notes_visibility','allow_calls'];
    const avatarAllowed = ['image/jpeg','image/png','image/gif','image/webp'];
    const maxAvatar = 10 * 1024 * 1024;
    let usernameTimer = null;
    let audioCtx = null;

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.from(document.querySelectorAll(sel));

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function showToast(message, type = 'info', ms = 2200) {
        const stack = $('#toast-stack');
        if (!stack) return;
        const el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' error' : '');
        el.textContent = message;
        stack.appendChild(el);
        setTimeout(() => { el.remove(); }, ms);
    }
    function showSaved(message = 'Saved') { showToast(message, 'info', 1500); }

    async function api(url, options = {}) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(options.body instanceof FormData ? {} : {'Content-Type': 'application/json'}),
                ...(options.headers || {}),
            },
            ...options,
        });
        const ct = res.headers.get('content-type') || '';
        const data = ct.includes('application/json') ? await res.json().catch(() => ({})) : {};
        if (!res.ok) {
            const err = new Error(data.message || 'Request failed');
            err.status = res.status;
            err.data = data;
            throw err;
        }
        return data;
    }

    function setActiveSection(section) {
        if (!sections.includes(section)) section = 'profile';
        $$('.section').forEach((el) => el.classList.toggle('active', el.id === `section-${section}`));
        $$('.nav-item, .tab-item').forEach((btn) => {
            const active = btn.dataset.section === section;
            btn.classList.toggle('active', active);
            if (btn.classList.contains('tab-item')) btn.setAttribute('aria-selected', active ? 'true' : 'false');
            btn.setAttribute('tabindex', active ? '0' : '-1');
        });
        if (location.hash !== `#${section}`) history.replaceState(null, '', `#${section}`);
        if (section === 'calls') refreshCallDevices();
    }

    function initHashNav() {
        const fromHash = (location.hash || '#profile').replace('#','');
        setActiveSection(sections.includes(fromHash) ? fromHash : 'profile');
        $$('.nav-item, .tab-item').forEach((btn) => btn.addEventListener('click', () => setActiveSection(btn.dataset.section)));
        window.addEventListener('hashchange', () => {
            const h = (location.hash || '#profile').replace('#','');
            setActiveSection(sections.includes(h) ? h : 'profile');
        });
    }

    function avatarInitialSrc(name, username) {
        const display = String(name || username || '?').trim() || '?';
        const seed = String(username || display).trim() || display;
        const initial = (Array.from(display)[0] || '?').toUpperCase().replace(/[<>&"']/g, '');
        const palette = ['#0ea5e9', '#8b5cf6', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#f59e0b'];
        let hash = 0;
        for (const char of seed) hash = (hash * 31 + (char.codePointAt(0) || 0)) >>> 0;
        const color = palette[hash % palette.length];
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="${color}"/><text x="48" y="61" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="44" font-weight="600" fill="#fff">${initial}</text></svg>`;
        return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    }

    function currentAvatarIdentity() {
        return {
            name: ($('#name')?.value || state.saved.name || '').trim(),
            username: ($('#username')?.value || state.saved.username || '').trim(),
        };
    }

    function isCustomAvatarUrl(url) {
        return !!(url && !String(url).includes('default-avatar.svg') && !String(url).startsWith('data:image/svg+xml'));
    }

    function setAvatarImage(url, name, username) {
        const img = $('#avatar-image');
        if (!img) return;
        const fallbackSrc = avatarInitialSrc(name, username);
        img.dataset.fallbackSrc = fallbackSrc;
        img.onerror = () => {
            if (img.dataset.fallbackSrc) img.src = img.dataset.fallbackSrc;
            img.onerror = null;
        };
        if (isCustomAvatarUrl(url)) {
            if (img.src !== fallbackSrc) img.src = fallbackSrc;
            const next = String(url);
            const pre = new Image();
            pre.onload = () => {
                if (isCustomAvatarUrl(state.saved.avatar) && String(state.saved.avatar) !== next) return;
                img.onerror = () => { img.src = fallbackSrc; img.onerror = null; };
                img.src = next;
            };
            pre.onerror = () => { img.src = fallbackSrc; };
            pre.src = next;
        } else {
            img.src = fallbackSrc;
        }
    }

    function syncAvatarEverywhere(url) {
        const normalized = isCustomAvatarUrl(url) ? String(url) : '';
        const { name, username } = currentAvatarIdentity();
        const fallbackSrc = avatarInitialSrc(name, username);
        const meta = document.querySelector('meta[name="user-avatar"]');
        if (meta) meta.setAttribute('content', normalized);

        const me = document.getElementById('me-avatar');
        if (me) {
            me.dataset.name = name || me.dataset.name || '';
            me.dataset.username = username || me.dataset.username || '';
            me.onerror = () => { me.src = fallbackSrc; me.onerror = null; };
            me.src = normalized || fallbackSrc;
        }

        document.querySelectorAll('img.avatar[data-self-avatar="1"]').forEach((node) => {
            node.dataset.name = name || node.dataset.name || '';
            node.dataset.username = username || node.dataset.username || '';
            node.onerror = () => { node.src = fallbackSrc; node.onerror = null; };
            node.src = normalized || fallbackSrc;
        });
    }

    function applyAvatar(url) {
        const { name, username } = currentAvatarIdentity();
        setAvatarImage(url, name, username);
        syncAvatarEverywhere(url);
    }

    function setSwitch(id, value) {
        const el = document.getElementById(id);
        if (el) el.setAttribute('aria-checked', value ? 'true' : 'false');
    }
    function getSwitch(id) {
        const el = document.getElementById(id);
        return el ? el.getAttribute('aria-checked') === 'true' : false;
    }

    function updateCounters() {
        const status = $('#status');
        const bio = $('#bio');
        if (status) {
            const n = (status.value || '').length;
            $('#status-count').textContent = String(n);
            $('#status-count').parentElement.classList.toggle('over', n > 60);
        }
        if (bio) {
            const n = (bio.value || '').length;
            $('#bio-count').textContent = String(n);
            $('#bio-count').parentElement.classList.toggle('over', n > 160);
        }
    }

    function setFieldError(name, message) {
        const el = document.querySelector(`[data-error-for="${name}"]`);
        if (el) el.textContent = message || '';
        const input = document.getElementById(name) || document.querySelector(`[name="${name}"]`);
        if (input && ['INPUT','TEXTAREA','SELECT'].includes(input.tagName)) {
            input.classList.toggle('input-invalid', !!message);
        }
    }

    function clearErrors() {
        $$('[data-error-for]').forEach((el) => el.textContent = '');
        $$('.input-invalid').forEach((el) => el.classList.remove('input-invalid'));
        $('#avatar-error').textContent = '';
        $('#crop-error').textContent = '';
    }

    function applyValidationErrors(errors) {
        if (!errors) return;
        Object.entries(errors).forEach(([key, msgs]) => {
            const message = Array.isArray(msgs) ? msgs[0] : String(msgs);
            if (key === 'avatar') $('#avatar-error').textContent = message;
            else if (key === 'confirmation') setFieldError('delete_confirmation', message);
            else setFieldError(key, message);
        });
    }

    function collectCurrent() {
        return {
            name: ($('#name').value || '').trim(),
            username: ($('#username').value || '').trim().toLowerCase(),
            email: ($('#email').value || '').trim().toLowerCase(),
            status: ($('#status').value || ''),
            bio: ($('#bio').value || ''),
            show_active_status: getSwitch('show-active-status'),
            read_receipts: getSwitch('read-receipts'),
            message_request_policy: $('#message-request-policy').value,
            notes_visibility: $('#notes-visibility').value,
            allow_calls: $('#allow-calls').value,
        };
    }

    function isDirty() {
        const current = collectCurrent();
        return saveFields.some((f) => {
            const a = current[f];
            const b = state.saved[f];
            if (typeof a === 'boolean' || typeof b === 'boolean') return !!a !== !!b;
            return String(a ?? '') !== String(b ?? '');
        });
    }

    function validateClientPreview() {
        const name = ($('#name').value || '').trim();
        const username = ($('#username').value || '').trim().toLowerCase();
        const email = ($('#email').value || '').trim();
        const status = $('#status').value || '';
        const bio = $('#bio').value || '';
        if (!name) return false;
        if (!/^[A-Za-z0-9_-]{3,30}$/.test(username)) return false;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return false;
        if (status.length > 60 || bio.length > 160) return false;
        if (!state.usernameOk) return false;
        return true;
    }

    function updateSaveBar() {
        const dirty = isDirty();
        $('#save-bar').classList.toggle('show', dirty);
        $('#save-btn').disabled = !dirty || !validateClientPreview() || state.saving;
    }

    function updateEmailBadge(verified) {
        const badge = $('#email-verify-badge');
        const btn = $('#verify-email-btn');
        if (verified) {
            badge.className = 'badge-state badge-ok';
            badge.textContent = 'Verified';
            btn.hidden = true;
        } else {
            badge.className = 'badge-state badge-warn';
            badge.textContent = 'Unverified';
            btn.hidden = false;
        }
    }

    function setUsernameStatus(kind, message, error = '') {
        const el = $('#username-status');
        el.className = kind === 'error' ? 'field-error' : 'field-success';
        el.textContent = message || '';
        el.dataset.error = error || '';
    }

    function applyThemePreference(pref, persistLocal = true) {
        const p = ['system','light','dark'].includes(pref) ? pref : 'system';
        const dark = p === 'dark' || (p === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        document.documentElement.dataset.themePreference = p;
        $$('.theme-option').forEach((btn) => {
            const active = btn.dataset.theme === p;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        if (persistLocal) {
            try { localStorage.setItem('incognito-theme-' + userId, p); } catch (e) {}
        }
    }

    function applyStateToForm(source) {
        $('#name').value = source.name || '';
        $('#username').value = source.username || '';
        $('#email').value = source.email || '';
        $('#status').value = source.status || '';
        $('#bio').value = source.bio || '';
        setSwitch('show-active-status', !!source.show_active_status);
        setSwitch('read-receipts', source.read_receipts !== false);
        $('#message-request-policy').value = source.message_request_policy || 'everyone';
        $('#notes-visibility').value = source.notes_visibility || 'friends';
        $('#allow-calls').value = source.allow_calls || 'friends';
        setSwitch('call-ringtone-switch', source.call_ringtone !== false);
        updateCounters();
        updateEmailBadge(!!source.email_verified);
        setSwitch('message-sounds-switch', !!source.message_sounds);
        $('#message-volume').value = String(source.message_sounds_volume ?? 100);
        $('#message-volume-value').textContent = String(source.message_sounds_volume ?? 100);
        setSwitch('desktop-notifications-switch', !!source.desktop_notifications);
        updateSoundControls();
        updateDesktopNotificationState();
        applyThemePreference(source.theme_preference || 'system', true);
        applyAvatar(source.avatar);
        state.usernameOk = true;
        setUsernameStatus('', '');
        $('#email-verify-status').textContent = '';
        updateSaveBar();
    }

    function scheduleUsernameCheck() {
        const value = ($('#username').value || '').trim().toLowerCase();
        const saved = (state.saved.username || '').toLowerCase();
        if (usernameTimer) clearTimeout(usernameTimer);
        if (value === saved) {
            state.usernameOk = true;
            setFieldError('username', '');
            setUsernameStatus('ok', 'This is your current username.');
            updateSaveBar();
            return;
        }
        if (!/^[A-Za-z0-9_-]{3,30}$/.test(value)) {
            state.usernameOk = false;
            setUsernameStatus('error', '', 'Use 3–30 letters, numbers, dashes or underscores.');
            setFieldError('username', 'Use 3–30 letters, numbers, dashes or underscores.');
            updateSaveBar();
            return;
        }
        setFieldError('username', '');
        setUsernameStatus('', 'Checking availability…');
        state.usernameOk = false;
        updateSaveBar();
        usernameTimer = setTimeout(async () => {
            try {
                const data = await api(`${routes.usernameCheck}?username=${encodeURIComponent(value)}`);
                if ((($('#username').value || '').trim().toLowerCase()) !== value) return;
                state.usernameOk = !!data.available;
                if (data.available) {
                    setFieldError('username', '');
                    setUsernameStatus('ok', data.message || 'Username is available.');
                } else {
                    const msg = data.message || 'This username is already taken.';
                    setUsernameStatus('error', msg, msg);
                    setFieldError('username', msg);
                }
            } catch (e) {
                if ((($('#username').value || '').trim().toLowerCase()) !== value) return;
                const msg = e?.data?.errors?.username?.[0] || e?.data?.message || 'Could not check username availability.';
                state.usernameOk = false;
                setUsernameStatus('error', msg, msg);
                setFieldError('username', msg);
            } finally {
                updateSaveBar();
            }
        }, 400);
    }

    function validateClient() {
        clearErrors();
        let ok = true;
        const name = ($('#name').value || '').trim();
        const username = ($('#username').value || '').trim().toLowerCase();
        const email = ($('#email').value || '').trim();
        const status = $('#status').value || '';
        const bio = $('#bio').value || '';
        if (!name) { setFieldError('name', 'Full name is required.'); ok = false; }
        if (!/^[A-Za-z0-9_-]{3,30}$/.test(username)) { setFieldError('username', 'Use 3–30 letters, numbers, dashes or underscores.'); ok = false; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setFieldError('email', 'Enter a valid email address.'); ok = false; }
        if (status.length > 60) { setFieldError('status', 'Status must be 60 characters or fewer.'); ok = false; }
        if (bio.length > 160) { setFieldError('bio', 'Bio must be 160 characters or fewer.'); ok = false; }
        if (!state.usernameOk) { setFieldError('username', $('#username-status').dataset.error || 'Please choose an available username.'); ok = false; }
        return ok;
    }

    async function saveProfile() {
        if (state.saving) return;
        if (!validateClient()) { updateSaveBar(); return; }
        state.saving = true;
        updateSaveBar();
        const payload = collectCurrent();
        try {
            const data = await api(routes.profileSave, { method: 'POST', body: JSON.stringify(payload) });
            const emailVerified = data.user ? !!data.user.email_verified : state.saved.email_verified;
            state.saved = { ...state.saved, ...payload, email_verified: emailVerified, avatar: data.user?.avatar || state.saved.avatar };
            applyStateToForm(state.saved);
            showToast('Settings saved');
        } catch (e) {
            applyValidationErrors(e?.data?.errors || {});
            showToast(e?.data?.message || 'Could not save settings.', 'error');
        } finally {
            state.saving = false;
            updateSaveBar();
        }
    }

    function discardChanges() {
        clearErrors();
        applyStateToForm(state.saved);
    }

    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => { if ((document.documentElement.dataset.themePreference || 'system') === 'system') applyThemePreference('system', false); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }

    async function setThemePreference(pref) {
        applyThemePreference(pref, true);
        try {
            await api(routes.appearance, { method: 'POST', body: JSON.stringify({ theme_preference: pref }) });
            state.saved.theme_preference = pref;
            showSaved('Theme saved');
        } catch (e) {
            showToast('Could not save theme.', 'error');
        }
    }

    function updateSoundControls() {
        const enabled = getSwitch('message-sounds-switch');
        $('#message-volume').disabled = !enabled;
        $('#test-sound-btn').disabled = !enabled;
        $('#sound-controls').style.opacity = enabled ? '1' : '0.6';
    }

    function desktopPermissionLabel() {
        if (!('Notification' in window)) return 'Desktop notifications are not supported in this browser.';
        const p = Notification.permission;
        if (p === 'granted') return 'Permission: granted.';
        if (p === 'denied') return 'Permission: blocked in browser settings.';
        return 'Permission: not requested yet.';
    }
    function updateDesktopNotificationState() {
        const el = $('#desktop-notification-state');
        if (el) el.textContent = desktopPermissionLabel();
    }

    async function saveNotificationPrefs(show = true) {
        const payload = {
            message_sounds: getSwitch('message-sounds-switch'),
            message_sounds_volume: Number($('#message-volume').value || 0),
            desktop_notifications: getSwitch('desktop-notifications-switch'),
            call_ringtone: getSwitch('call-ringtone-switch'),
        };
        try {
            await api(routes.notifications, { method: 'POST', body: JSON.stringify(payload) });
            state.saved = { ...state.saved, ...payload };
            window.incognitoCalls?.setRingtone(payload.call_ringtone, payload.message_sounds_volume);
            if (show) showSaved();
        } catch (e) {
            showToast('Could not save notification settings.', 'error');
        }
    }

    function playTestSound() {
        if (!getSwitch('message-sounds-switch')) return;
        const volume = Math.max(0, Math.min(1, Number($('#message-volume').value || 0) / 100));
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            audioCtx = audioCtx || new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.value = 0.0001;
            o.connect(g); g.connect(audioCtx.destination);
            const now = audioCtx.currentTime;
            g.gain.exponentialRampToValueAtTime(Math.max(0.0001, 0.08 * volume), now + 0.01);
            g.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
            o.start(now); o.stop(now + 0.2);
        } catch (e) {}
    }

    async function toggleDesktopNotifications() {
        const btn = $('#desktop-notifications-switch');
        const turningOn = btn.getAttribute('aria-checked') !== 'true';
        if (turningOn) {
            if (!('Notification' in window)) {
                showToast('Desktop notifications are not supported in this browser.', 'error');
                updateDesktopNotificationState();
                return;
            }
            let permission = Notification.permission;
            if (permission === 'default') {
                try { permission = await Notification.requestPermission(); } catch (e) { permission = Notification.permission; }
            }
            if (permission !== 'granted') {
                setSwitch('desktop-notifications-switch', false);
                updateDesktopNotificationState();
                showToast(permission === 'denied' ? 'Notification permission blocked by browser.' : 'Notification permission not granted.', 'error');
                await saveNotificationPrefs(false);
                return;
            }
            setSwitch('desktop-notifications-switch', true);
        } else {
            setSwitch('desktop-notifications-switch', false);
        }
        updateDesktopNotificationState();
        await saveNotificationPrefs(true);
    }

    function secureCallContext() {
        return window.isSecureContext
            || location.hostname === 'localhost'
            || location.hostname === '127.0.0.1'
            || location.hostname === '[::1]';
    }

    function deviceStorageKey(kind) {
        return `incognito-${kind}-device-${userId}`;
    }

    function storedDevice(kind) {
        try { return localStorage.getItem(deviceStorageKey(kind)) || ''; } catch (e) { return ''; }
    }

    function storeDevice(kind, id) {
        try { localStorage.setItem(deviceStorageKey(kind), id || ''); } catch (e) {}
    }

    function fillDeviceSelect(select, devices, savedId, fallbackLabel) {
        if (!select) return;
        const options = [{ id: '', label: 'System default' }].concat(
            devices.map((device, index) => ({
                id: device.deviceId,
                label: device.label || `${fallbackLabel} ${index + 1}`,
            }))
        );
        select.innerHTML = options.map((device) => (
            `<option value="${escapeHtml(device.id)}">${escapeHtml(device.label)}</option>`
        )).join('');
        const valid = options.some((device) => device.id === savedId);
        select.value = valid ? savedId : '';
    }

    async function refreshCallDevices() {
        const micSelect = $('#call-mic-select');
        const speakerSelect = $('#call-speaker-select');
        if (!micSelect || !speakerSelect) return;

        if (!navigator.mediaDevices?.enumerateDevices) {
            micSelect.innerHTML = '<option value="">Not supported</option>';
            speakerSelect.innerHTML = '<option value="">Not supported</option>';
            micSelect.disabled = true;
            speakerSelect.disabled = true;
            $('#speaker-device-help').textContent = 'This browser cannot list audio devices.';
            return;
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            fillDeviceSelect(
                micSelect,
                devices.filter((device) => device.kind === 'audioinput'),
                storedDevice('mic'),
                'Microphone'
            );
            const canSelectOutput = typeof HTMLMediaElement !== 'undefined'
                && typeof HTMLMediaElement.prototype.setSinkId === 'function';
            fillDeviceSelect(
                speakerSelect,
                canSelectOutput ? devices.filter((device) => device.kind === 'audiooutput') : [],
                storedDevice('speaker'),
                'Speaker'
            );
            speakerSelect.disabled = !canSelectOutput;
            $('#speaker-device-help').textContent = canSelectOutput
                ? 'Used for call audio only.'
                : 'Speaker selection is not supported in this browser; the system default is used.';
        } catch (e) {
            $('#speaker-device-help').textContent = 'Could not read audio devices.';
        }
    }

    async function stopMicTest() {
        const test = state.micTest;
        if (!test) return;
        state.micTest = null;
        if (test.raf) cancelAnimationFrame(test.raf);
        if (test.timer) clearTimeout(test.timer);
        test.stream.getTracks().forEach((track) => { try { track.stop(); } catch (e) {} });
        if (test.ctx) { try { await test.ctx.close(); } catch (e) {} }
        $('#mic-level-bar').style.width = '0%';
        $('#test-mic-btn').textContent = 'Test microphone';
        $('#mic-test-status').textContent = 'Test stopped.';
    }

    function micErrorMessage(error) {
        const name = error?.name || '';
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
            return 'Microphone permission was denied. Allow microphone access in your browser settings, then try again.';
        }
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
            return 'No microphone was found. Connect a microphone and try again.';
        }
        if (name === 'NotReadableError' || name === 'TrackStartError') {
            return 'Your microphone is in use by another app. Close it and try again.';
        }
        return error?.message || 'Could not start the microphone test.';
    }

    async function toggleMicTest() {
        if (state.micTest) {
            await stopMicTest();
            return;
        }
        if (!secureCallContext()) {
            $('#call-https-warning').style.display = 'block';
            $('#mic-test-status').textContent = 'Audio calls require HTTPS (localhost is allowed).';
            return;
        }
        if (!navigator.mediaDevices?.getUserMedia) {
            $('#mic-test-status').textContent = 'This browser cannot access the microphone.';
            return;
        }

        const btn = $('#test-mic-btn');
        btn.disabled = true;
        $('#mic-test-status').textContent = 'Requesting microphone permission…';

        try {
            const audio = {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            };
            const deviceId = $('#call-mic-select').value;
            if (deviceId) audio.deviceId = { exact: deviceId };

            const stream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
            await refreshCallDevices();

            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) throw new Error('Audio analysis is not supported in this browser.');
            const ctx = new Ctx();
            if (ctx.state === 'suspended') await ctx.resume().catch(() => {});
            const analyser = ctx.createAnalyser();
            analyser.fftSize = 1024;
            ctx.createMediaStreamSource(stream).connect(analyser);
            const data = new Uint8Array(analyser.fftSize);

            state.micTest = { stream, ctx, raf: null, timer: null };
            const tick = () => {
                if (!state.micTest) return;
                analyser.getByteTimeDomainData(data);
                let peak = 0;
                for (let i = 0; i < data.length; i += 1) {
                    peak = Math.max(peak, Math.abs(data[i] - 128));
                }
                const level = Math.min(100, Math.round((peak / 128) * 180));
                $('#mic-level-bar').style.width = `${level}%`;
                state.micTest.raf = requestAnimationFrame(tick);
            };
            tick();

            state.micTest.timer = setTimeout(() => { stopMicTest(); }, 15000);
            btn.textContent = 'Stop test';
            $('#mic-test-status').textContent = 'Listening… speak normally. The test stops automatically after 15 seconds.';
        } catch (error) {
            $('#mic-test-status').textContent = micErrorMessage(error);
            $('#mic-level-bar').style.width = '0%';
            await stopMicTest();
            $('#mic-test-status').textContent = micErrorMessage(error);
        } finally {
            btn.disabled = false;
        }
    }

    function openCrop(file) {
        if (!avatarAllowed.includes(file.type)) {
            $('#avatar-error').textContent = 'Choose a JPEG, PNG, GIF, or WebP image.';
            return;
        }
        if (file.size > maxAvatar) {
            $('#avatar-error').textContent = 'Your photo must be 10 MB or smaller.';
            return;
        }
        $('#avatar-error').textContent = '';
        const reader = new FileReader();
        reader.onload = () => {
            const img = new Image();
            img.onload = () => initCrop(img, file.type === 'image/png' ? 'image/png' : 'image/jpeg');
            img.onerror = () => { $('#avatar-error').textContent = 'Could not read that image.'; };
            img.src = reader.result;
        };
        reader.onerror = () => { $('#avatar-error').textContent = 'Could not read that image.'; };
        reader.readAsDataURL(file);
    }

    function initCrop(image, outType) {
        const modal = $('#crop-modal');
        const zoom = $('#crop-zoom');
        const area = $('#crop-area');
        $('#crop-image').src = image.src;
        state.crop = { image, outType, scale: 1, minScale: 1, x: 0, y: 0, dragging: false, startX: 0, startY: 0, baseX: 0, baseY: 0 };
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => {
            const size = area.clientWidth || 320;
            const minScale = Math.max(size / image.naturalWidth, size / image.naturalHeight);
            state.crop.minScale = minScale;
            state.crop.scale = minScale;
            state.crop.x = 0; state.crop.y = 0;
            zoom.min = String(minScale);
            zoom.max = String(minScale * 3);
            zoom.step = String(Math.max(0.001, (minScale * 2) / 200));
            zoom.value = String(minScale);
            renderCrop();
        });
        $('#crop-confirm').focus();
    }

    function renderCrop() {
        const c = state.crop; if (!c) return;
        const area = $('#crop-area');
        const size = area.clientWidth || 320;
        const w = c.image.naturalWidth * c.scale;
        const h = c.image.naturalHeight * c.scale;
        const limitX = Math.max(0, (w - size) / 2);
        const limitY = Math.max(0, (h - size) / 2);
        c.x = Math.max(-limitX, Math.min(limitX, c.x));
        c.y = Math.max(-limitY, Math.min(limitY, c.y));
        const img = $('#crop-image');
        img.style.width = w + 'px';
        img.style.height = h + 'px';
        img.style.transform = `translate(calc(-50% + ${c.x}px), calc(-50% + ${c.y}px))`;
        updatePreviews();
    }

    function getCroppedDataUrl(outSize = 512) {
        const c = state.crop;
        const area = $('#crop-area');
        const size = area.clientWidth || 320;
        const canvas = document.createElement('canvas');
        canvas.width = outSize; canvas.height = outSize;
        const ctx = canvas.getContext('2d');
        const w = c.image.naturalWidth * c.scale;
        const h = c.image.naturalHeight * c.scale;
        const drawX = (size - w) / 2 + c.x;
        const drawY = (size - h) / 2 + c.y;
        const ratio = outSize / size;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0,0,outSize,outSize);
        ctx.drawImage(c.image, drawX * ratio, drawY * ratio, w * ratio, h * ratio);
        return canvas.toDataURL(c.outType === 'image/png' ? 'image/png' : 'image/jpeg', 0.92);
    }

    function updatePreviews() {
        try {
            const url = getCroppedDataUrl(144);
            $('#preview-circle').src = url;
            $('#preview-square').src = url;
        } catch (e) {}
    }

    function closeCrop() {
        state.crop = null;
        $('#crop-modal').classList.remove('show');
        $('#crop-modal').setAttribute('aria-hidden', 'true');
        $('#avatar-file').value = '';
    }

    async function uploadCroppedAvatar() {
        if (!state.crop) return;
        $('#crop-error').textContent = '';
        const btn = $('#crop-confirm');
        btn.disabled = true;
        let blob;
        try {
            const dataUrl = getCroppedDataUrl(512);
            blob = await (await fetch(dataUrl)).blob();
        } catch (e) {
            $('#crop-error').textContent = 'Could not prepare the cropped image.';
            btn.disabled = false; return;
        }
        if (blob.size > maxAvatar) {
            $('#crop-error').textContent = 'Cropped image is too large (max 10 MB).';
            btn.disabled = false; return;
        }
        const ext = blob.type === 'image/png' ? 'png' : 'jpg';
        const file = new File([blob], `avatar.${ext}`, { type: blob.type });
        const fd = new FormData();
        fd.append('avatar', file);

        const progressWrap = $('#avatar-progress');
        const bar = $('#avatar-progress-bar');
        progressWrap.hidden = false; bar.style.width = '0%';

        try {
            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', routes.avatar);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                xhr.upload.onprogress = (e) => { if (e.lengthComputable) bar.style.width = Math.round((e.loaded / e.total) * 100) + '%'; };
                xhr.onload = () => {
                    let data = {};
                    try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                    if (xhr.status >= 200 && xhr.status < 300) resolve(data);
                    else { const err = new Error(data.message || 'Upload failed'); err.data = data; err.status = xhr.status; reject(err); }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));
                xhr.send(fd);
            });
            state.saved.avatar = result.avatar;
            applyAvatar(result.avatar);
            closeCrop();
            showToast('Profile photo updated');
        } catch (e) {
            const msg = e?.data?.errors?.avatar?.[0] || e?.data?.message || e.message || 'Could not upload photo.';
            $('#crop-error').textContent = msg;
            $('#avatar-error').textContent = msg;
        } finally {
            btn.disabled = false;
            setTimeout(() => { progressWrap.hidden = true; bar.style.width = '0%'; }, 400);
        }
    }

    async function removeAvatar() {
        if (!confirm('Remove your profile photo?')) return;
        try {
            const data = await api(routes.avatarRemove, { method: 'DELETE' });
            state.saved.avatar = data.avatar;
            applyAvatar(data.avatar);
            showToast('Profile photo removed');
        } catch (e) {
            showToast(e?.data?.message || 'Could not remove photo.', 'error');
        }
    }

    function renderBlockedUsers() {
        const list = $('#blocked-users-list');
        if (!state.blocked || !state.blocked.length) {
            list.innerHTML = '<div class="empty-state">No blocked users.</div>';
            return;
        }
        list.innerHTML = state.blocked.map((u) => {
            const fallback = avatarInitialSrc(u.name || '?', u.username || u.name || '');
            const src = isCustomAvatarUrl(u.avatar) ? u.avatar : fallback;
            return `
            <div class="list-card" data-blocked-id="${u.id}">
                <div class="list-main">
                    <img src="${escapeHtml(src)}" alt="" data-fallback="${escapeHtml(fallback)}" onerror="if(this.dataset.fallback){this.onerror=null;this.src=this.dataset.fallback;}">
                    <div class="list-info">
                        <strong>${escapeHtml(u.name || '')}</strong>
                        <span>@${escapeHtml(u.username || '')}</span>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm unblock-btn" data-user-id="${u.id}">Unblock</button>
            </div>`;
        }).join('');
    }

    async function unblockUser(userId) {
        try {
            await api(`${routes.blocked}/${userId}`, { method: 'DELETE' });
            state.blocked = state.blocked.filter((u) => Number(u.id) !== Number(userId));
            renderBlockedUsers();
            showToast('User unblocked');
        } catch (e) {
            showToast(e?.data?.message || 'Could not unblock user.', 'error');
        }
    }

    function renderSessions() {
        const list = $('#sessions-list');
        if (!state.sessions || !state.sessions.length) {
            list.innerHTML = '<div class="empty-state">No active sessions.</div>';
            return;
        }
        list.innerHTML = state.sessions.map((s) => `
            <div class="list-card">
                <div class="list-info" style="min-width:0;">
                    <strong>${escapeHtml(s.device || 'Unknown device')} ${s.is_current ? '<span class="badge-state badge-ok" style="margin-left:6px;">Current session</span>' : ''}</strong>
                    <span>${escapeHtml(s.ip_address || 'Unknown IP')} · Last active ${escapeHtml(s.last_active_human || 'recently')}</span>
                </div>
                ${s.is_current ? '' : `<button type="button" class="btn btn-secondary btn-sm logout-session-btn" data-session-id="${escapeHtml(s.id)}">Log out</button>`}
            </div>
        `).join('');
    }

    async function logoutSession(sessionId) {
        try {
            await api(`${routes.sessions}/${encodeURIComponent(sessionId)}`, { method: 'DELETE' });
            state.sessions = state.sessions.filter((s) => s.id !== sessionId);
            renderSessions();
            showToast('Session logged out');
        } catch (e) {
            showToast(e?.data?.message || 'Could not log out session.', 'error');
        }
    }

    async function logoutOtherSessions() {
        const password = prompt('Enter your password to log out of all other devices:');
        if (password === null) return;
        if (!password) { showToast('Password is required.', 'error'); return; }
        try {
            await api(routes.logoutOthers, { method: 'DELETE', body: JSON.stringify({ password }) });
            state.sessions = state.sessions.filter((s) => s.is_current);
            renderSessions();
            showToast('Other sessions logged out');
        } catch (e) {
            showToast(e?.data?.errors?.password?.[0] || e?.data?.message || 'Could not log out other sessions.', 'error');
        }
    }

    function passwordStrength(pw) {
        let score = 0;
        if (!pw) return { label: '', width: '0%', color: 'transparent' };
        if (pw.length >= 8) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw) || pw.length >= 12) score++;
        const map = [
            { label: 'Very weak', color: '#ef4444', width: '20%' },
            { label: 'Weak', color: '#f97316', width: '40%' },
            { label: 'Fair', color: '#eab308', width: '60%' },
            { label: 'Good', color: '#22c55e', width: '80%' },
            { label: 'Strong', color: '#16a34a', width: '100%' },
        ];
        return map[Math.max(0, Math.min(4, score))];
    }
    function updatePasswordStrength() {
        const pw = $('#password').value || '';
        const s = passwordStrength(pw);
        $('#password-strength-bar').style.width = pw ? s.width : '0%';
        $('#password-strength-bar').style.background = s.color || 'transparent';
        $('#password-strength-text').textContent = pw ? `Strength: ${s.label}` : '';
    }

    async function submitPassword(e) {
        e.preventDefault();
        ['current_password','password','password_confirmation'].forEach((k) => setFieldError(k, ''));
        const payload = {
            current_password: $('#current_password').value,
            password: $('#password').value,
            password_confirmation: $('#password_confirmation').value,
        };
        const submit = $('#password-submit');
        submit.disabled = true;
        try {
            await api(routes.password, { method: 'PUT', body: JSON.stringify(payload) });
            $('#password-form').reset();
            updatePasswordStrength();
            showToast('Password updated');
        } catch (err) {
            applyValidationErrors(err?.data?.errors || {});
            showToast(err?.data?.message || 'Could not update password.', 'error');
        } finally {
            submit.disabled = false;
        }
    }

    async function submitDelete(e) {
        e.preventDefault();
        setFieldError('delete_password', '');
        setFieldError('delete_confirmation', '');
        const payload = {
            password: $('#delete_password').value,
            confirmation: $('#delete_confirmation').value,
        };
        if (payload.confirmation !== 'DELETE') {
            setFieldError('delete_confirmation', 'Type DELETE to confirm account deletion.');
            return;
        }
        if (!confirm('Permanently delete your account? This cannot be undone.')) return;
        const submit = $('#delete-submit');
        submit.disabled = true;
        try {
            const data = await api(routes.deleteAccount, { method: 'DELETE', body: JSON.stringify(payload) });
            window.location.href = data.redirect || routes.login;
        } catch (err) {
            applyValidationErrors(err?.data?.errors || {});
            showToast(err?.data?.message || 'Could not delete account.', 'error');
            submit.disabled = false;
        }
    }

    async function sendVerification() {
        const btn = $('#verify-email-btn');
        btn.disabled = true;
        $('#email-verify-status').textContent = '';
        try {
            const data = await api(routes.verify, { method: 'POST', body: JSON.stringify({}) });
            $('#email-verify-status').className = 'field-success';
            $('#email-verify-status').textContent = data.message || 'Verification link sent.';
            showToast(data.message || 'Verification link sent.');
        } catch (e) {
            $('#email-verify-status').className = 'field-error';
            $('#email-verify-status').textContent = e?.data?.message || 'Could not send verification email.';
            showToast(e?.data?.message || 'Could not send verification email.', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    function init() {
        initHashNav();
        applyStateToForm(state.saved);
        renderBlockedUsers();
        renderSessions();

        const refreshAvatarPreview = () => {
            if (!isCustomAvatarUrl(state.saved.avatar)) {
                applyAvatar(state.saved.avatar);
            }
        };
        ['name','email','status','bio'].forEach((id) => {
            document.getElementById(id).addEventListener('input', () => {
                if (id === 'name') refreshAvatarPreview();
                if (id === 'status' || id === 'bio') updateCounters();
                updateSaveBar();
            });
        });
        $('#username').addEventListener('input', () => {
            $('#username').value = $('#username').value.toLowerCase();
            scheduleUsernameCheck();
            refreshAvatarPreview();
            updateSaveBar();
        });

        $$('.save-tracked').forEach((el) => {
            if (el.classList.contains('switch')) {
                el.addEventListener('click', () => {
                    el.setAttribute('aria-checked', el.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
                    updateSaveBar();
                });
                el.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); el.click(); } });
            } else {
                el.addEventListener('change', updateSaveBar);
                el.addEventListener('input', updateSaveBar);
            }
        });

        $('#save-btn').addEventListener('click', saveProfile);
        $('#discard-btn').addEventListener('click', discardChanges);

        $$('.theme-option').forEach((btn) => btn.addEventListener('click', () => setThemePreference(btn.dataset.theme)));

        $('#message-sounds-switch').addEventListener('click', async () => {
            const el = $('#message-sounds-switch');
            el.setAttribute('aria-checked', el.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
            updateSoundControls();
            await saveNotificationPrefs(true);
        });
        $('#message-sounds-switch').addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); $('#message-sounds-switch').click(); } });
        $('#message-volume').addEventListener('input', () => { $('#message-volume-value').textContent = $('#message-volume').value; });
        $('#message-volume').addEventListener('change', async () => { await saveNotificationPrefs(true); });
        $('#test-sound-btn').addEventListener('click', playTestSound);
        $('#desktop-notifications-switch').addEventListener('click', toggleDesktopNotifications);
        $('#desktop-notifications-switch').addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); $('#desktop-notifications-switch').click(); } });

        if (!secureCallContext()) $('#call-https-warning').style.display = 'block';
        refreshCallDevices();
        navigator.mediaDevices?.addEventListener?.('devicechange', () => refreshCallDevices());
        $('#call-mic-select').addEventListener('change', () => storeDevice('mic', $('#call-mic-select').value));
        $('#call-speaker-select').addEventListener('change', () => storeDevice('speaker', $('#call-speaker-select').value));
        $('#test-mic-btn').addEventListener('click', toggleMicTest);
        $('#call-ringtone-switch').addEventListener('click', async () => {
            const el = $('#call-ringtone-switch');
            el.setAttribute('aria-checked', el.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
            await saveNotificationPrefs(true);
        });
        $('#call-ringtone-switch').addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); $('#call-ringtone-switch').click(); } });
        window.addEventListener('pagehide', () => { if (state.micTest) stopMicTest(); });

        const openPicker = () => $('#avatar-file').click();
        $('#avatar-trigger').addEventListener('click', openPicker);
        $('#avatar-change-btn').addEventListener('click', openPicker);
        $('#avatar-remove-btn').addEventListener('click', removeAvatar);
        $('#avatar-file').addEventListener('change', (e) => { const f = e.target.files && e.target.files[0]; if (f) openCrop(f); });
        $('#crop-cancel').addEventListener('click', closeCrop);
        $('#crop-confirm').addEventListener('click', uploadCroppedAvatar);
        $('#crop-zoom').addEventListener('input', () => { if (!state.crop) return; state.crop.scale = Number($('#crop-zoom').value); renderCrop(); });

        const cropImg = $('#crop-image');
        const startDrag = (clientX, clientY) => { if (!state.crop) return; state.crop.dragging = true; state.crop.startX = clientX; state.crop.startY = clientY; state.crop.baseX = state.crop.x; state.crop.baseY = state.crop.y; cropImg.style.cursor = 'grabbing'; };
        const moveDrag = (clientX, clientY) => { if (!state.crop || !state.crop.dragging) return; state.crop.x = state.crop.baseX + (clientX - state.crop.startX); state.crop.y = state.crop.baseY + (clientY - state.crop.startY); renderCrop(); };
        const endDrag = () => { if (!state.crop) return; state.crop.dragging = false; cropImg.style.cursor = 'grab'; };
        $('#crop-area').addEventListener('pointerdown', (e) => { startDrag(e.clientX, e.clientY); $('#crop-area').setPointerCapture?.(e.pointerId); });
        $('#crop-area').addEventListener('pointermove', (e) => moveDrag(e.clientX, e.clientY));
        $('#crop-area').addEventListener('pointerup', endDrag);
        $('#crop-area').addEventListener('pointercancel', endDrag);
        $('#crop-modal').addEventListener('click', (e) => { if (e.target.id === 'crop-modal') closeCrop(); });

        $('#verify-email-btn').addEventListener('click', sendVerification);

        $('#blocked-users-list').addEventListener('click', (e) => {
            const btn = e.target.closest('.unblock-btn');
            if (btn) unblockUser(btn.dataset.userId);
        });
        $('#sessions-list').addEventListener('click', (e) => {
            const btn = e.target.closest('.logout-session-btn');
            if (btn) logoutSession(btn.dataset.sessionId);
        });
        $('#logout-others-btn').addEventListener('click', logoutOtherSessions);

        $('#password').addEventListener('input', updatePasswordStrength);
        $('#password-form').addEventListener('submit', submitPassword);
        $('#delete-form').addEventListener('submit', submitDelete);

        window.addEventListener('beforeunload', (e) => {
            if (isDirty()) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    init();
})();
</script>
@endpush
