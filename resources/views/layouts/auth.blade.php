<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Incognito Messenger')</title>
    @php
        $authUser = auth()->user()?->loadMissing('profile');
        $themePreference = $authUser?->themePreference() ?? 'system';
    @endphp
    <script>
        (function () {
            try {
                var id = @json(auth()->id());
                var server = @json($themePreference);
                var key = id ? ('incognito-theme-' + id) : 'incognito-theme';
                var pref = localStorage.getItem(key) || server || 'system';
                if (pref !== 'light' && pref !== 'dark' && pref !== 'system') pref = 'system';
                try { localStorage.setItem(key, pref); } catch (e) {}
                var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
                document.documentElement.dataset.themePreference = pref;
            } catch (e) {
                document.documentElement.dataset.theme = 'dark';
                document.documentElement.dataset.themePreference = 'system';
            }
        })();
    </script>
    <style>
        :root {
            --bg: #0f172a; --bg-grad-a: #1e3a5f; --surface: #1e293b; --surface-2: #162033; --input-bg: #0f172a;
            --line: #334155; --text: #e2e8f0; --muted: #94a3b8; --hint: #64748b; --accent: #0ea5e9;
            --accent-hover: #38bdf8; --link: #7dd3fc; --success-bg: #064e3b; --success-text: #a7f3d0;
            --error-bg: #7f1d1d; --error-text: #fecaca; --danger: #ef4444; --danger-hover: #dc2626;
            --focus: 0 0 0 3px rgba(14,165,233,.25);
        }
        html[data-theme="light"] {
            --bg: #eef3f8; --bg-grad-a: #dbeafe; --surface: #ffffff; --surface-2: #f8fafc; --input-bg: #f8fafc;
            --line: #d7e0ea; --text: #0f172a; --muted: #475569; --hint: #64748b; --accent: #0284c7;
            --accent-hover: #0369a1; --link: #0369a1; --success-bg: #dcfce7; --success-text: #166534;
            --error-bg: #fee2e2; --error-text: #991b1b; --danger: #dc2626; --danger-hover: #b91c1c;
            --focus: 0 0 0 3px rgba(2,132,199,.2);
        }
        * { box-sizing: border-box; margin: 0; }
        body { min-height: 100vh; font-family: 'Instrument Sans', system-ui, sans-serif; color: var(--text);
               background: radial-gradient(circle at 15% 10%, var(--bg-grad-a), var(--bg) 55%); display: flex;
               align-items: center; justify-content: center; padding: 24px; transition: background .2s ease, color .2s ease; }
        .shell { width: 100%; max-width: 460px; }
        .shell.wide { max-width: 860px; }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand .logo { font-size: 34px; }
        .brand h1 { font-size: 22px; letter-spacing: -.02em; margin-top: 6px; }
        .brand p { color: var(--muted); font-size: 13px; margin-top: 4px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 26px;
                box-shadow: 0 24px 60px rgba(0,0,0,.2); }
        .card h2 { font-size: 17px; margin-bottom: 18px; }
        label { display: block; font-size: 12.5px; color: var(--muted); margin: 12px 0 5px; }
        input[type=text], input[type=email], input[type=password], input[type=number], textarea, select {
            width: 100%; padding: 11px 13px; border-radius: 10px; border: 1px solid var(--line);
            background: var(--input-bg); color: var(--text); font: inherit; font-size: 14px; outline: none; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; }
        input:focus, textarea:focus, select:focus { border-color: var(--accent); box-shadow: var(--focus); }
        input:focus-visible, textarea:focus-visible, select:focus-visible, button:focus-visible, a:focus-visible, [role="tab"]:focus-visible, [role="switch"]:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        textarea { resize: vertical; min-height: 76px; }
        .check { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13px; color: var(--text); }
        .hint { display: block; margin-top: 4px; font-size: 11.5px; color: var(--hint); }
        .btn, button.primary, button.secondary, button.danger {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 40px; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--line);
            background: var(--surface-2); color: var(--text); font: inherit; font-size: 14px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: background .15s ease, border-color .15s ease, transform .15s ease, opacity .15s ease; }
        .btn:hover, button.secondary:hover { border-color: var(--accent); }
        .btn:active, button.primary:active, button.secondary:active, button.danger:active { transform: translateY(1px); }
        .btn:disabled, button:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        button.primary, .btn-primary { width: 100%; margin-top: 20px; border: 0; background: var(--accent); color: #fff; }
        button.primary:hover, .btn-primary:hover { background: var(--accent-hover); }
        .btn-secondary { background: var(--surface-2); }
        .btn-danger, button.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn-danger:hover, button.danger:hover { background: var(--danger-hover); border-color: var(--danger-hover); }
        .links { margin-top: 18px; text-align: center; font-size: 13px; color: var(--muted); }
        .links a, .back { color: var(--link); text-decoration: none; }
        .back:hover, .links a:hover { text-decoration: underline; }
        .alert { border-radius: 10px; padding: 10px 13px; font-size: 13px; margin-bottom: 14px; }
        .alert-success { background: var(--success-bg); color: var(--success-text); }
        .alert-error { background: var(--error-bg); color: var(--error-text); }
        .alert ul { margin: 0; padding-left: 18px; }
        .back { display: inline-block; margin-top: 16px; font-size: 13px; }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition: none !important; animation: none !important; scroll-behavior: auto !important; }
        }
        @stack('styles')
    </style>
</head>
<body class="@yield('body-class')">
<div class="shell @yield('shell-class')">
    @hasSection('brand')
        @yield('brand')
    @else
        <div class="brand">
            <div class="logo">🕶️</div>
            <h1>Incognito Messenger</h1>
            <p>Private chats. Real conversations.</p>
        </div>
    @endif

    <div class="card @yield('card-class')">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
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
@stack('scripts')
</body>
</html>
