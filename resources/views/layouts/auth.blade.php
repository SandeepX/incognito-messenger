<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Incognito Messenger')</title>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body { min-height: 100vh; font-family: 'Instrument Sans', system-ui, sans-serif; color: #e2e8f0;
               background: radial-gradient(circle at 15% 10%, #1e3a5f, #0f172a 55%); display: flex;
               align-items: center; justify-content: center; padding: 24px; }
        .shell { width: 100%; max-width: 460px; }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand .logo { font-size: 34px; }
        .brand h1 { font-size: 22px; letter-spacing: -.02em; margin-top: 6px; }
        .brand p { color: #94a3b8; font-size: 13px; margin-top: 4px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 18px; padding: 26px;
                box-shadow: 0 24px 60px rgba(0,0,0,.35); }
        .card h2 { font-size: 17px; margin-bottom: 18px; }
        label { display: block; font-size: 12.5px; color: #94a3b8; margin: 12px 0 5px; }
        input[type=text], input[type=email], input[type=password], textarea {
            width: 100%; padding: 11px 13px; border-radius: 10px; border: 1px solid #334155;
            background: #0f172a; color: #e2e8f0; font: inherit; font-size: 14px; outline: none; }
        input:focus, textarea:focus { border-color: #0ea5e9; }
        textarea { resize: vertical; min-height: 76px; }
        .check { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13px; color: #cbd5e1; }
        .hint { display: block; margin-top: 4px; font-size: 11.5px; color: #64748b; }
        button.primary { width: 100%; margin-top: 20px; padding: 12px; border: 0; border-radius: 10px;
            background: #0ea5e9; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button.primary:hover { background: #38bdf8; }
        .links { margin-top: 18px; text-align: center; font-size: 13px; color: #94a3b8; }
        .links a { color: #7dd3fc; text-decoration: none; }
        .alert { border-radius: 10px; padding: 10px 13px; font-size: 13px; margin-bottom: 14px; }
        .alert-success { background: #064e3b; color: #a7f3d0; }
        .alert-error { background: #7f1d1d; color: #fecaca; }
        .alert ul { margin: 0; padding-left: 18px; }
        .back { display: inline-block; margin-top: 16px; font-size: 13px; color: #7dd3fc; text-decoration: none; }
    </style>
</head>
<body>
<div class="shell">
    <div class="brand">
        <div class="logo">🕶️</div>
        <h1>Incognito Messenger</h1>
        <p>Private chats. Real conversations.</p>
    </div>

    <div class="card">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>
</div>
</body>
</html>
