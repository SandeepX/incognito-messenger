@extends('layouts.auth')

@section('title', 'Log in — Incognito Messenger')

@section('content')
    <h2>Welcome back</h2>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>

        <button class="primary" type="submit">Log in</button>
    </form>
    <div class="links">New here? <a href="{{ route('register') }}">Create an account</a></div>
@endsection
