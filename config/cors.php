<?php

/*
 * The app is served from a single origin (the same host:port serves both
 * Laravel and, through Caddy, Reverb). CORS rarely comes into play, but the
 * values are env-driven so the LAN HTTPS origin works when credentials or
 * a separate front-end origin are used.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', env('CORS_ALLOWED_ORIGINS', env('APP_URL', '*'))),
)));

return [

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
