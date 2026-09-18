<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvalidCredentials;
use App\Modules\Identity\Domain\Exception\ReauthenticationRequired;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * "Prove it again" for the handful of acts that deserve it (ADR 0034).
 *
 * A session lasts up to ninety days (ADR 0028) and an idle window can be hours
 * (ADR 0029). Both are right for reading work and moving a card; neither is a
 * good answer to somebody sitting down at an unlocked laptop and erasing a
 * colleague. This is the middle ground: the session stays, and the act asks.
 *
 * Deliberately NOT a permission and NOT a middleware. A permission says who
 * may; this says how recently they proved they are still the who. A middleware
 * would have to be attached route by route anyway, and the list of acts that
 * deserve it is short enough to read: taking somebody's second factor off,
 * erasing a person, and changing what the organization requires of everybody.
 */
final class RecentAuthentication
{
    /**
     * How long one confirmation lasts.
     *
     * Fifteen minutes because the unit of this is a task, not a session: an
     * administrator offboarding three people should type their password once,
     * and somebody who walked away for lunch should not find the door still
     * open. A shorter window makes the prompt a reflex, which is the thing that
     * makes a person type their password into whatever asks.
     */
    public const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Refuse unless this session proved itself inside the window.
     *
     * Throws rather than returning a bool on purpose: a caller that forgets to
     * check a bool has silently skipped the safeguard, and this codebase has
     * seen that shape often enough to know how it ends.
     */
    public function require(Request $request): void
    {
        $session = $this->sessionFrom($request);

        $provedAt = $session?->reauthenticated_at;

        if ($provedAt !== null && $provedAt->gt(now()->subMinutes(self::WINDOW_MINUTES))) {
            return;
        }

        throw new ReauthenticationRequired(
            'Confirm your password to do this.',
            ['window_minutes' => self::WINDOW_MINUTES],
        );
    }

    /**
     * The password, once, to open the window.
     *
     * Not the second factor as well. The factor was proved when this session
     * began and cannot be replayed (ADR 0030); what is being tested here is
     * that the person at the keyboard is still the one who knows the password,
     * which is exactly what an unlocked laptop does not.
     */
    public function confirm(UserModel $user, string $password, Request $request): void
    {
        if ($user->password_hash === null || ! Hash::check($password, $user->password_hash)) {
            $this->audit->record('auth.reauthentication_failed', [], $request);

            throw new InvalidCredentials('That password is not correct.');
        }

        $session = $this->sessionFrom($request);

        $session?->forceFill(['reauthenticated_at' => now()])->save();

        $this->audit->record('auth.reauthenticated', [
            'window_minutes' => self::WINDOW_MINUTES,
        ], $request);
    }

    private function sessionFrom(Request $request): ?SessionModel
    {
        /** @var UserModel|null $user */
        $user = $request->user();
        $session = $user?->currentAccessToken();

        return $session instanceof SessionModel ? $session : null;
    }
}
