<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controller\AuthController;
use Illuminate\Support\Facades\Route;

/**
 * Auth endpoints (docs/05 §2).
 *
 * Login is rate limited far more aggressively than everything else: it is the
 * one endpoint an attacker can call without credentials (docs/05 §7).
 */
Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
    });
});
