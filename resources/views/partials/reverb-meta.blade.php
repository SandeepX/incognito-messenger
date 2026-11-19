{{--
    Browser configuration for Laravel Echo / Reverb.

    The browser derives wsHost, wsPort and TLS from window.location. These
    meta tags exist only for optional overrides (split port deployments) and
    to expose non-secret values the client needs, so nothing about the host
    or port is hardcoded in the compiled JavaScript.

    Include this partial in the <head> of every authenticated page.
--}}
<meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
<meta name="reverb-port" content="{{ config('app.reverb_browser_port') }}">
<meta name="reverb-path" content="{{ config('app.reverb_browser_path') }}">
<meta name="app-debug" content="{{ config('app.debug') ? '1' : '0' }}">
