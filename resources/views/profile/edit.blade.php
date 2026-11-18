@extends('layouts.auth')

@section('title', 'My profile — Incognito Messenger')

@section('content')
    <h2>Edit profile</h2>

    <div style="text-align:center;margin-bottom:18px">
        <img src="{{ $user->avatarUrl() }}" alt="Your avatar" style="width:80px;height:80px;border-radius:50%;object-fit:cover">
    </div>

    <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data">
        @csrf
        <label for="avatar">Profile picture (JPEG, PNG, GIF or WebP — max 2 MB)</label>
        <input id="avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required
               style="font-size:13px;color:#cbd5e1">
        <button class="primary" type="submit" style="margin-top:10px">Upload picture</button>
    </form>

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

        <button class="primary" type="submit">Save changes</button>
    </form>

    <a class="back" href="{{ route('home') }}">← Back to messages</a>
@endsection
