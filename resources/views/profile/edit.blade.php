@extends('layouts.auth')

@section('title', 'My profile — Incognito Messenger')

@section('content')
    <h2>Edit profile</h2>

    <div style="text-align:center;margin-bottom:18px">
        <img id="profile-avatar" src="{{ $user->avatarUrl() }}" alt="{{ $user->name }}'s avatar"
             data-name="{{ $user->name }}" style="width:80px;height:80px;border-radius:50%;object-fit:cover">
    </div>

    <script>
        // Keep a useful avatar even when the image file is missing or broken.
        (function () {
            const img = document.getElementById('profile-avatar');
            const fallback = () => {
                if (img.dataset.fallback) return;
                img.dataset.fallback = '1';
                const name = (img.dataset.name || img.alt || '?').trim();
                const initial = (name[0] || '?').toUpperCase().replace(/[<>&"']/g, '');
                const colors = ['#0ea5e9', '#8b5cf6', '#f97316', '#10b981', '#e11d48', '#6366f1', '#14b8a6', '#f59e0b'];
                let hash = 0;
                for (const char of name) hash = (hash * 31 + char.codePointAt(0)) >>> 0;
                const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="${colors[hash % colors.length]}"/><text x="48" y="61" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="44" font-weight="600" fill="#fff">${initial}</text></svg>`;
                img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
            };

            img.addEventListener('error', fallback);
            if (img.complete && img.naturalWidth === 0) fallback();
        })();
    </script>

    <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data">
        @csrf
        <label for="avatar">Profile picture (JPEG, PNG, GIF or WebP — max 10 MB)</label>
        <input id="avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required
               style="font-size:13px;color:#cbd5e1">
        <div id="avatar-error" class="alert alert-error" hidden style="margin-top:10px"></div>
        <button class="primary" type="submit" style="margin-top:10px">Upload picture</button>
    </form>

    <script>
        // Fail fast with a clear message instead of waiting for a round trip.
        (function () {
            const input = document.getElementById('avatar');
            const error = document.getElementById('avatar-error');
            input.closest('form').addEventListener('submit', function (event) {
                error.hidden = true;
                const file = input.files[0];
                if (file && file.size > 10 * 1024 * 1024) {
                    event.preventDefault();
                    error.textContent = 'That picture is larger than 10 MB. Please choose a smaller image.';
                    error.hidden = false;
                }
            });
        })();
    </script>

    <hr style="border:0;border-top:1px solid #334155;margin:24px 0">

    <form method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('PUT')
        <label for="name">Full name</label>
        <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required>

        <label for="username">Username</label>
        <input id="username" type="text" name="username" value="{{ old('username', $user->username) }}" required>

        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>

        <label for="status">Status message</label>
        <input id="status" type="text" name="status" maxlength="120" value="{{ old('status', $user->profile?->status) }}" placeholder="Available, busy, on holiday…">

        <label for="bio">Bio</label>
        <textarea id="bio" name="bio" maxlength="500" placeholder="Tell people a little about yourself">{{ old('bio', $user->profile?->bio) }}</textarea>

        <hr style="border:0;border-top:1px solid #334155;margin:20px 0 8px">

        <input type="hidden" name="message_sounds" value="0">
        <label class="check" for="message_sounds">
            <input id="message_sounds" type="checkbox" name="message_sounds" value="1"
                @checked((bool) old('message_sounds', $user->profile?->message_sounds ?? true))>
            Message sounds
        </label>
        <small class="hint">Play a subtle tone when messages arrive or send.</small>

        <input type="hidden" name="show_active_status" value="0">
        <label class="check" for="show_active_status">
            <input id="show_active_status" type="checkbox" name="show_active_status" value="1"
                @checked((bool) old('show_active_status', $user->profile?->show_active_status ?? true))>
            Show active status
        </label>
        <small class="hint">Let accepted friends see when you are online or last active.</small>

        <button class="primary" type="submit">Save changes</button>
    </form>

    <a class="back" href="{{ route('home') }}">← Back to messages</a>
@endsection
