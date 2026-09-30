<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controller\ApiTokenController;
use App\Modules\Identity\Http\Controller\AuthController;
use App\Modules\Identity\Http\Controller\InvitationAcceptController;
use App\Modules\Identity\Http\Controller\ServiceAccountController;
use App\Modules\Identity\Http\Controller\SingleSignOnController;
use App\Modules\Identity\Http\Controller\SsoConnectionController;
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

        // Switching organization (ADR 0050). Under `auth.` on purpose: an API
        // token is bound to the organization it was made in, and LimitApiTokens
        // refuses every `auth.*` route but `auth.me` — so a token cannot hop.
        Route::get('organizations', [AuthController::class, 'organizations'])
            ->name('auth.organizations');
        Route::post('organization', [AuthController::class, 'switchOrganization'])
            ->middleware('throttle:writes')
            ->name('auth.organization.switch');
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
 * A person's own API tokens (ADR 0049).
 *
 * Named `api_tokens.*` because the name is what `LimitApiTokens` refuses to a
 * token: every route here is for a browser session, so a leaked token cannot
 * mint another. Listing and revoking need no permission — somebody who lost
 * `api_token.create` must still be able to see and end what they made.
 */
// Service accounts (ADR 0059): members that are not people, with tokens an
// administrator issues. Named `service_accounts.*` so LimitApiTokens refuses
// every one of them to a token — a token that could reach these could issue
// its own successor or promote the account it belongs to.
Route::prefix('service-accounts')
    ->middleware(['auth:sanctum', 'permission:service_account.manage'])
    ->group(function (): void {
        Route::get('', [ServiceAccountController::class, 'index'])->name('service_accounts.index');
        Route::post('', [ServiceAccountController::class, 'store'])
            ->middleware('throttle:writes')->name('service_accounts.store');
        Route::delete('{id}', [ServiceAccountController::class, 'destroy'])
            ->middleware('throttle:writes')->name('service_accounts.destroy');
        Route::get('{id}/tokens', [ServiceAccountController::class, 'tokens'])->name('service_accounts.tokens');
        Route::post('{id}/tokens', [ServiceAccountController::class, 'issueToken'])
            ->middleware('throttle:writes')->name('service_accounts.tokens.store');
        Route::delete('{id}/tokens/{token}', [ServiceAccountController::class, 'revokeToken'])
            ->middleware('throttle:writes')->name('service_accounts.tokens.destroy');
    });

Route::prefix('me/api-tokens')->middleware('auth:sanctum')->group(function (): void {
    Route::get('', [ApiTokenController::class, 'index'])->name('api_tokens.index');
    Route::post('', [ApiTokenController::class, 'store'])
        ->middleware(['permission:api_token.create', 'throttle:writes'])
        ->name('api_tokens.store');
    Route::delete('{id}', [ApiTokenController::class, 'destroy'])
        ->middleware('throttle:writes')
        ->name('api_tokens.destroy');
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

/**
 * Signing in through an organization's identity provider (ADR 0052).
 *
 * Reachable with nothing, like login — they are how a session is made. The web
 * server calls all four; the browser never talks to this API directly.
 * `start` carries an address and is throttled like login; the two after it
 * carry single-use random values and get a limiter of their own.
 */
Route::prefix('auth/sso')->group(function (): void {
    Route::post('start', [SingleSignOnController::class, 'start'])
        ->middleware('throttle:login')
        ->name('auth.sso.start');
    Route::post('acs', [SingleSignOnController::class, 'consume'])
        ->middleware('throttle:sso')
        ->name('auth.sso.consume');
    Route::post('complete', [SingleSignOnController::class, 'complete'])
        ->middleware('throttle:sso')
        ->name('auth.sso.complete');
    Route::get('metadata', [SingleSignOnController::class, 'metadata'])
        ->middleware('throttle:sso')
        ->name('auth.sso.metadata');
});

/**
 * The organization's identity provider (ADR 0052).
 *
 * `sso.manage` on every route, the read included: the connection names who
 * may vouch for everybody here, and the read is what a person uses to decide
 * whether to change it. Every write also asks for the password again, in the
 * controller, which is also what keeps API tokens out of it.
 */
Route::prefix('sso-connection')->middleware(['auth:sanctum', 'permission:sso.manage'])->group(function (): void {
    Route::get('', [SsoConnectionController::class, 'show'])->name('sso.connection.show');
    Route::put('', [SsoConnectionController::class, 'save'])
        ->middleware('throttle:writes')
        ->name('sso.connection.save');
    Route::delete('', [SsoConnectionController::class, 'destroy'])
        ->middleware('throttle:writes')
        ->name('sso.connection.destroy');
    Route::patch('enforcement', [SsoConnectionController::class, 'enforcement'])
        ->middleware('throttle:writes')
        ->name('sso.connection.enforcement');
});
