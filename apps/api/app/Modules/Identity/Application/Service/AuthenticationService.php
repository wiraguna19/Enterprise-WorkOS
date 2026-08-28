<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvalidCredentials;
use App\Modules\Identity\Domain\Exception\NoActiveMembership;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login, logout, and session lifecycle.
 *
 * See docs/06-auth-and-authorization.md §1.
 */
final class AuthenticationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function login(
        string $email,
        string $password,
        Request $request,
        ?string $organizationId = null,
    ): array {
        $user = UserModel::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        // Constant-time: the same work and the same error whether the account
        // exists or the password is wrong. Anything else is a user enumeration
        // oracle (docs/06 §1).
        $passwordValid = $user !== null
            && $user->password_hash !== null
            && Hash::check($password, $user->password_hash);

        if (! $passwordValid) {
            Hash::make($password); // equalise timing on the unknown-user path
            $this->audit->record('auth.login_failed', [
                'email' => $email,
                'reason' => 'invalid_credentials',
            ], $request);

            throw new InvalidCredentials('These credentials do not match our records.');
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login_failed', [
                'email' => $email,
                'reason' => 'deactivated',
            ], $request);

            throw new InvalidCredentials('These credentials do not match our records.');
        }

        $membership = $this->resolveMembership($user, $organizationId);

        return DB::transaction(function () use ($user, $membership, $request): array {
            $plainSecret = Str::random(48);

            $session = new SessionModel;
            $session->forceFill([
                'id' => SessionModel::newId(),
                'user_id' => $user->getKey(),
                'organization_id' => $membership->organization_id,
                'token_hash' => hash('sha256', $plainSecret),
                'name' => 'web',
                'abilities' => ['*'],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
            ])->save();

            $user->forceFill(['last_login_at' => now()])->save();

            $this->audit->record('auth.login', [
                'session_id' => $session->getKey(),
                'organization_id' => $membership->organization_id,
            ], $request, actorUserId: (string) $user->getKey());

            return [
                'token' => $session->getKey().'|'.$plainSecret,
                'session' => $session,
                'user' => $user,
                'membership' => $membership,
            ];
        });
    }

    public function logout(SessionModel $session, Request $request): void
    {
        $session->revoke('logout');

        $this->audit->record('auth.logout', [
            'session_id' => $session->getKey(),
        ], $request);
    }

    /**
     * Revoke every session for a user.
     *
     * Called on password change, MFA change, role change, and membership
     * revocation. This is the reason sessions are opaque and server-side rather
     * than JWTs: revocation is immediate (docs/06 §1).
     */
    public function revokeAllSessions(string $userId, string $reason, ?Request $request = null): int
    {
        $count = SessionModel::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->audit->record('auth.session_revoked', [
            'user_id' => $userId,
            'reason' => $reason,
            'sessions_revoked' => $count,
        ], $request);

        return $count;
    }

    private function resolveMembership(UserModel $user, ?string $organizationId): MembershipModel
    {
        $query = MembershipModel::query()
            ->withoutGlobalScopes() // pre-tenant: this call is what CHOOSES the tenant
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $membership = $query->orderBy('joined_at')->first();

        if ($membership === null) {
            throw new NoActiveMembership('You do not have access to any organization.');
        }

        return $membership;
    }
}
