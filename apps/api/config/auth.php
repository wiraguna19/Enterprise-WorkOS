<?php

declare(strict_types=1);
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;

/*
 * There was no config/auth.php at all, so Laravel's defaults applied: the `web`
 * guard and a user provider pointing at App\Models\User — a class this codebase
 * does not have. Everything worked because `auth:sanctum` sets the guard per
 * request, but every static analysis of `$request->user()` resolved to a
 * missing class, and anything relying on the default guard would have failed
 * quietly at runtime.
 */

return [
    'defaults' => [
        // This is a stateless JSON API (docs/05 §1). There is no session guard
        // to fall back to, so the default IS the API guard.
        'guard' => 'sanctum',
        'passwords' => 'users',
    ],

    'guards' => [
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => UserModel::class,
        ],
    ],

    // Password resets are not implemented yet; the table name is fixed here so
    // the eventual migration and this config cannot disagree.
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
