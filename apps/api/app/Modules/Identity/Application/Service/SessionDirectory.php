<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\SessionNotFound;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Illuminate\Http\Request;

/**
 * The devices signed in to an account, and ending one (ADR 0023).
 *
 * `sessions` has recorded an IP address, a user agent, a creation time and a
 * last-used time since Phase 1, and nothing has ever read them. The write path
 * is complete; there is no read path. That is the same defect as the audit log
 * two slices ago, and it is worse here, because this is the table that answers
 * the question a person asks when they fear their account has been taken:
 * **what else is signed in as me, and can I stop it?**
 *
 * Two rules shape everything below:
 *
 * - **A person sees their OWN sessions, and nobody else's.** There is no
 *   permission for this and there should not be: it is not an administrative
 *   power, it is the account looking at itself. An administrator who needs
 *   somebody signed out everywhere erases or deactivates them.
 * - **The current session is marked, never hidden.** A list that quietly
 *   omitted the device you are holding would read as "somebody else is signed
 *   in", and a list that let you end it without saying so signs you out of the
 *   screen you are standing on.
 */
final class SessionDirectory
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(UserModel $user, string $currentSessionId): array
    {
        $sessions = SessionModel::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return array_values($sessions->map(fn (SessionModel $session): array => [
            'id' => $session->getKey(),
            'current' => (string) $session->getKey() === $currentSessionId,
            // The raw string, not a guess at a device name. Parsing user agents
            // is a losing game with a long tail, and "Chrome on a Mac" derived
            // wrongly is worse than the string somebody can read for
            // themselves.
            'user_agent' => $session->user_agent,
            'ip_address' => $session->ip_address === null ? null : (string) $session->ip_address,
            'created_at' => $session->created_at->toIso8601String(),
            'last_used_at' => $session->last_used_at?->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ])->all());
    }

    /**
     * End one session.
     *
     * Scoped to the user in the request as well as to the id: a session id is a
     * uuid somebody could paste, and ending a stranger's session because you
     * know its id would be an offboarding tool for anybody.
     */
    public function revoke(UserModel $user, string $sessionId, string $currentSessionId, Request $request): void
    {
        $session = SessionModel::query()
            ->where('id', $sessionId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($session === null) {
            throw new SessionNotFound('That session is not one of yours, or has already ended.');
        }

        $isCurrent = (string) $session->getKey() === $currentSessionId;

        $session->revoke($isCurrent ? 'signed_out' : 'ended_from_another_device');

        $this->audit->record('auth.session_revoked', [
            'session_id' => $session->getKey(),
            'was_current' => $isCurrent,
        ], $request);
    }

    /**
     * End every session except the one asking.
     *
     * The button somebody presses when they think they have been compromised,
     * and the reason it keeps the current one: a control that signed you out
     * too would leave you unable to change your password afterwards, which is
     * the very next thing that should happen.
     *
     * @return int how many ended
     */
    public function revokeOthers(UserModel $user, string $currentSessionId, Request $request): int
    {
        $others = SessionModel::query()
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->whereNull('revoked_at')
            ->get();

        foreach ($others as $session) {
            $session->revoke('ended_from_another_device');
        }

        if ($others->isNotEmpty()) {
            $this->audit->record('auth.sessions_revoked', [
                'count' => $others->count(),
                'kept' => $currentSessionId,
            ], $request);
        }

        return $others->count();
    }
}
