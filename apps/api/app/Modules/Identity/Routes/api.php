<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controller\AuthController;
use App\Modules\Identity\Http\Controller\InvitationAcceptController;
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

    /**
     * The code prompt (ADR 0030).
     *
     * Unauthenticated, because the session it leads to does not exist yet — the
     * challenge in the body is what says who is asking, and it is encrypted
     * with the application key and dead in two minutes. Throttled as
     * aggressively as login for the same reason: it is reachable without a
     * session, and six digits is a small space to guess in.
     */
    Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])
        ->middleware('throttle:login')
        ->name('auth.mfa.verify');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');

        // What else is signed in as me, and stopping it (ADR 0023). No
        // `permission:` gate on purpose: these are an account looking at
        // itself, not authority over anybody, and every one is scoped to the
        // user in the request.
        Route::get('sessions', [AuthController::class, 'sessions'])->name('auth.sessions');
        Route::delete('sessions/{id}', [AuthController::class, 'revokeSession'])
            ->middleware('throttle:writes')
            ->name('auth.sessions.revoke');
        Route::delete('sessions', [AuthController::class, 'revokeOtherSessions'])
            ->middleware('throttle:writes')
            ->name('auth.sessions.revoke_others');

        // Enrolling and removing a second factor (ADR 0030). No `permission:`
        // gate, for the same reason the session endpoints have none: this is an
        // account deciding about itself, and an organization-wide key would
        // mean an administrator could be refused their own security settings.
        // "Prove it again" for the sensitive acts (ADR 0034). Throttled like
        // login, because it takes a password and is reachable with a session
        // somebody else may be holding.
        Route::post('reauthenticate', [AuthController::class, 'reauthenticate'])
            ->middleware('throttle:login')
            ->name('auth.reauthenticate');

        Route::post('mfa', [AuthController::class, 'beginMfa'])
            ->middleware('throttle:writes')
            ->name('auth.mfa.begin');
        Route::post('mfa/confirm', [AuthController::class, 'confirmMfa'])
            ->middleware('throttle:login')
            ->name('auth.mfa.confirm');
        Route::delete('mfa', [AuthController::class, 'disableMfa'])
            ->middleware('throttle:writes')
            ->name('auth.mfa.disable');
        Route::post('mfa/recovery-codes', [AuthController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:writes')
            ->name('auth.mfa.recovery_codes');
    });
});

/**
 * The public half of an invitation (ADR 0017).
 *
 * Unauthenticated because the person holding the link has no account yet, which
 * makes these the only endpoints besides login reachable with nothing at all —
 * so they carry a throttle of their own, keyed by token and address. Both
 * answer identically for a token that is wrong, expired, revoked or already
 * accepted: telling those apart is an oracle for guessing tokens.
 */
Route::prefix('invitations')->middleware('throttle:invitation')->group(function (): void {
    Route::get('{token}/preview', [InvitationAcceptController::class, 'show'])
        ->name('invitations.preview');
    Route::post('{token}/accept', [InvitationAcceptController::class, 'accept'])
        ->name('invitations.accept');
});
