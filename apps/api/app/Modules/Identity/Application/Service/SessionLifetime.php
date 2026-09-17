<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Platform\Domain\Contract\SessionPolicy;
use Illuminate\Http\Request;

/**
 * Applying an organization's session lifetime to sessions that already exist
 * (ADR 0028).
 *
 * The reason this class exists at all: a policy that only governs sessions
 * issued AFTER it was set is not a policy for thirty days. An administrator who
 * shortens the window to seven days because a laptop went missing has, under
 * the naive implementation, changed nothing about the laptop — every session on
 * it keeps the thirty days it was born with, and the screen says seven.
 *
 * So lowering CLAMPS. Every live session in the organization whose expiry is
 * now further out than the new policy allows is pulled back to the new limit.
 *
 * Raising does NOT extend. A session issued under a seven-day promise was
 * reviewed, if it was reviewed at all, as a seven-day session; stretching it to
 * ninety because somebody relaxed the setting hands out access nobody looked
 * at. The new number governs the next sign-in, which is the moment the person
 * proves who they are again.
 *
 * Note what this deliberately is NOT: revocation. A clamped session stays
 * valid until its new expiry. "End every session in this organization now" is a
 * different, louder act than "shorten the window", and collapsing the two into
 * one setting would mean an administrator adjusting a policy signs out their
 * whole company by accident.
 */
final class SessionLifetime
{
    public function __construct(
        private readonly SessionPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /** How long a session issued right now, in this organization, may live. */
    public function daysFor(string $organizationId): int
    {
        return $this->policy->sessionLifetimeDays($organizationId);
    }

    /**
     * Pull back every live session that outlives the new limit.
     *
     * @return int the number of sessions shortened
     */
    public function clampTo(string $organizationId, int $days, ?Request $request = null): int
    {
        $limit = now()->addDays($days);

        $shortened = SessionModel::query()
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            // Only the ones that outlive the new limit. Without this the
            // statement would also push EXPIRED sessions back into the future,
            // reviving rows that `PruneExpiredSessions` is on its way to
            // delete — a policy change that signs people back in.
            ->where('expires_at', '>', $limit)
            ->update(['expires_at' => $limit]);

        if ($shortened > 0) {
            $this->audit->record('auth.sessions_shortened', [
                'session_lifetime_days' => $days,
                'sessions_shortened' => $shortened,
            ], $request);
        }

        return $shortened;
    }
}
