@extends('layouts.auth')

@section('title', 'Register — Incognito Messenger')

@section('content')
    <h2>Create your account</h2>
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <label for="name">Full name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">

        <label for="username">Username</label>
        <input id="username" type="text" name="username" value="{{ old('username') }}" required autocomplete="username" placeholder="e.g. sandeep">

        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">

        <label for="password">Password <span style="color:#64748b">(at least 8 characters)</span></label>
        <input id="password" type="password" name="password" required autocomplete="new-password">

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        <button class="primary" type="submit">Create account</button>
    </form>
    <div class="links">Already have an account? <a href="{{ route('login') }}">Log in</a></div>
@endsection
