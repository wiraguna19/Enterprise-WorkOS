<?php

declare(strict_types=1);

/**
 * Broadcasting (docs/01 §8, docs/07 §8).
 *
 * The default is `null`, and that is a product decision rather than a
 * development convenience: every screen is correct when polled, and a
 * deployment without a socket server is supported. Turning real-time on is
 * setting BROADCAST_CONNECTION=reverb and running the server; turning it off is
 * removing that, with nothing else to unwind.
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // A socket server that is slow or down must not hold a web
                // request open: the publisher swallows the failure, but only
                // after this timeout, so the timeout is the actual bound on
                // what real-time can cost a write.
                'timeout' => 3,
            ],
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
