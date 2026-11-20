<?php

return [

    'ice_servers' => array_values(array_filter([
        [
            'urls' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBRTC_STUN_URLS', 'stun:stun.l.google.com:19302')),
            ))),
        ],
        env('WEBRTC_TURN_URLS') ? [
            'urls' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBRTC_TURN_URLS')),
            ))),
            'username' => env('WEBRTC_TURN_USERNAME'),
            'credential' => env('WEBRTC_TURN_CREDENTIAL'),
        ] : null,
    ], function ($server) {
        return $server !== null && ! empty($server['urls']);
    })),

];
