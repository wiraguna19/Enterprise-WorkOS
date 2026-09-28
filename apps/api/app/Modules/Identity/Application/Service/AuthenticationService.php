<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvalidCredentials;
use App\Modules\Identity\Domain\Exception\NoActiveMembership;
use App\Modules\Identity\Domain\Exception\SingleSignOnRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
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
        private readonly TenantContext $tenant,
        private readonly SessionLifetime $lifetime,
        private readonly SsoConnections $sso,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * Sign in — or, for an account with a second factor, ask for it.
     *
     * The return is a discriminated pair rather than a nullable token, so a
     * caller cannot forget to look: `mfa_required` is true and there is a
     * challenge, or it is false and there is a session. Nothing in between.
     *
     * @return array{mfa_required: true, challenge: string}|array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
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

        $membership = $this->membershipForPassword($user, $organizationId, $email, $request);

        // A second factor stops here: the password was right, and that is not
        // yet a session (ADR 0030). What crosses back is a short-lived
        // challenge naming this user and this organization, encrypted with the
        // application key — a client cannot forge one, and cannot turn one into
        // anything except the code prompt it is for.
        if ($user->hasMfaEnabled()) {
            $this->audit->record('auth.mfa_challenged', [
                'email' => $email,
            ], $request, actorUserId: (string) $user->getKey());

            return [
                'mfa_required' => true,
                'challenge' => $this->issueChallenge($user, $membership),
            ];
        }

        return $this->issueSession($user, $membership, $request);
    }

    /**
     * The token that stands in for a half-finished sign-in.
     *
     * Encrypted rather than stored. A row per attempt would be a table to
     * write, index, prune and reason about for a value that is meaningless
     * after two minutes; Laravel's encrypter is authenticated, so a challenge
     * that has been edited does not decrypt at all. Two minutes is the whole
     * of its power: somebody who steals one has already supplied the password
     * it came from, and the code prompt throttles like the login it belongs to.
     */
    private function issueChallenge(UserModel $user, MembershipModel $membership): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => (string) $user->getKey(),
            'organization_id' => (string) $membership->organization_id,
            // `now()`, not `time()`: this product's clock is Laravel's, so a
            // test that travels forward finds an expired challenge and the
            // expiry is actually covered.
            'expires_at' => now()->getTimestamp() + 120,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Who a challenge names — checked again, not trusted.
     *
     * Public because `MultiFactor` owns the second half of the sign-in: it
     * opens the challenge, verifies the code, and asks for the session. That
     * keeps the dependency pointing one way (MultiFactor → this) instead of
     * two services each holding the other.
     *
     * @return array{0: UserModel, 1: MembershipModel}
     */
    public function openChallenge(string $challenge): array
    {
        try {
            /** @var array{user_id?: string, organization_id?: string, expires_at?: int} $payload */
            $payload = json_decode(Crypt::decryptString($challenge), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // One answer for a forged challenge, a corrupted one, and one from
            // an application key that has since been rotated. None of the three
            // is information a client is owed.
            throw new InvalidCredentials('This sign-in has expired. Please start again.');
        }

        if (($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw new InvalidCredentials('This sign-in has expired. Please start again.');
        }

        $user = UserModel::query()->find($payload['user_id'] ?? '');

        if ($user === null || ! $user->isActive()) {
            throw new InvalidCredentials('These credentials do not match our records.');
        }

        $membership = MembershipModel::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $payload['organization_id'] ?? '')
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            // Membership is re-checked rather than trusted from the challenge:
            // two minutes is long enough for somebody to be offboarded between
            // the password and the code, and that is precisely the person this
            // whole feature exists to keep out.
            throw new NoActiveMembership('You do not have access to any organization.');
        }

        return [$user, $membership];
    }

    /**
     * @return array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function issueSession(
        UserModel $user,
        MembershipModel $membership,
        Request $request,
        bool $viaMfa = false,
        // The organization this person is switching FROM, when this session is
        // a switch rather than a sign-in (ADR 0050). It changes two things:
        // the re-authentication window stays shut, and the audit entry says
        // what happened instead of calling it a login.
        ?string $switchedFrom = null,
        // How the person proved who they are: 'password', or 'sso' when the
        // organization's identity provider vouched for them (ADR 0052). Kept
        // on the row because three rules read it for as long as it lives.
        string $authenticatedBy = 'password',
    ): array {
        // Read BEFORE the transaction and BEFORE the tenant resolver: how long
        // this session may live belongs to the organization being signed in to
        // (ADR 0028), not to the thirty days that were written here in Phase 1
        // and were the same for every tenant in the product.
        $lifetimeDays = $this->lifetime->daysFor((string) $membership->organization_id);

        return DB::transaction(function () use ($user, $membership, $request, $lifetimeDays, $viaMfa, $switchedFrom, $authenticatedBy): array {
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
                'expires_at' => now()->addDays($lifetimeDays),
                // Signing in IS proving yourself, so the window for sensitive
                // acts opens here (ADR 0034) rather than making somebody type
                // their password twice in a row. Switching organization is not:
                // nobody typed anything, and a window opened by a click would
                // let a borrowed laptop erase somebody in the second tenant.
                'reauthenticated_at' => $switchedFrom === null ? now() : null,
                'authenticated_by' => $authenticatedBy,
                'created_at' => now(),
            ])->save();

            if ($switchedFrom === null) {
                $user->forceFill(['last_login_at' => now()])->save();
            }

            // Bound to the organization, not merely describing it in metadata.
            // Signing in happens before the tenant resolver has run, so this
            // row was being written with a null `organization_id` — a platform
            // event — while naming the organization inside its own metadata.
            // The audit view is tenant-scoped, so every login in the product
            // was invisible to the organization it was a login to.
            $this->tenant->runFor(
                (string) $membership->organization_id,
                function () use ($session, $lifetimeDays, $viaMfa, $switchedFrom, $authenticatedBy, $request, $user): void {
                    if ($switchedFrom === null) {
                        $this->audit->record('auth.login', [
                            'session_id' => $session->getKey(),
                            'session_lifetime_days' => $lifetimeDays,
                            'second_factor' => $viaMfa,
                            'authenticated_by' => $authenticatedBy,
                        ], $request, actorUserId: (string) $user->getKey());

                        return;
                    }

                    $this->audit->record('auth.organization_switched_in', [
                        'session_id' => $session->getKey(),
                        'from_organization_id' => $switchedFrom,
                        'session_lifetime_days' => $lifetimeDays,
                    ], $request, actorUserId: (string) $user->getKey());
                },
            );

            return [
                'mfa_required' => false,
                'token' => $session->getKey().'|'.$plainSecret,
                'session' => $session,
                'user' => $user,
                'membership' => $membership,
            ];
        });
    }

    /**
     * The organizations this person may act in, for the switcher (ADR 0050).
     *
     * Active memberships only — a revoked one is an organization they left,
     * and listing it would offer a switch the API refuses. Read with a join
     * rather than through the organization directory once per row: the list is
     * short, but it is asked by a menu, and a menu should not cost N queries.
     *
     * @return list<array{id: string, name: string, slug: string, current: bool}>
     */
    public function organizationsFor(
        UserModel $user,
        ?string $currentOrganizationId,
        // A session an organization's IdP vouched for can go nowhere else
        // (ADR 0052), so the switcher is shown only where it stands — the menu
        // then has no "Switch to" section, which is the honest interface.
        bool $currentOnly = false,
    ): array {
        // Across tenants by definition — the whole point is the OTHER
        // organizations — so it says so, through platform mode. With Row-Level
        // Security on, a query run as the bound tenant would see one membership
        // and the switcher would quietly offer nowhere to go (ADR 0051).
        /** @var Collection<int, object{id: string, name: string, slug: string}> $rows */
        $rows = $this->tenant->runAsPlatform(
            'list the organizations a person belongs to',
            function () use ($user, $currentOnly, $currentOrganizationId): Collection {
                $query = DB::table('memberships')
                    ->join('organizations', 'organizations.id', '=', 'memberships.organization_id')
                    ->where('memberships.user_id', $user->getKey())
                    ->where('memberships.status', 'active')
                    ->whereNull('memberships.revoked_at');

                if ($currentOnly) {
                    $query->where('memberships.organization_id', $currentOrganizationId);
                }

                return $query
                    ->orderBy('organizations.name')
                    ->get(['organizations.id', 'organizations.name', 'organizations.slug']);
            },
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'current' => (string) $row->id === $currentOrganizationId,
            ];
        }

        return $out;
    }

    /**
     * Move to another organization this person belongs to (docs/06 §1, ADR 0050).
     *
     * docs/06 has said since Phase 1 that "switching organizations issues a new
     * token", and nothing did. It issues one here — a new SESSION, bound to the
     * other organization, living by THAT organization's lifetime and answering
     * to its second-factor requirement — and ends the one that asked. Never a
     * header or a parameter that re-scopes an existing session: the tenant is
     * a property of the session row, and a row that could change tenant would
     * be a row the client could point anywhere.
     *
     * The membership is looked up by the organization id AND this user, so
     * naming an organization you do not belong to answers exactly as naming
     * one that does not exist.
     *
     * @return array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function switchOrganization(
        UserModel $user,
        SessionModel $current,
        string $organizationId,
        Request $request,
    ): array {
        // An identity provider vouches for one organization's people in that
        // organization. Letting its session hop would let Acme's IdP sign
        // somebody into Globex, which never agreed to trust it (ADR 0052).
        if ($current->isSingleSignOn()) {
            throw SingleSignOnRefused::sessionBound('reach another organization');
        }

        if (! Str::isUuid($organizationId)) {
            throw new NoActiveMembership('You do not belong to that organization.');
        }

        // The target membership belongs to ANOTHER tenant than the one this
        // session is bound to, so it is read in platform mode — explicitly, and
        // logged — rather than invisibly failing under Row-Level Security
        // (ADR 0051). The lookup is still by this user AND that organization.
        /** @var MembershipModel $membership */
        $membership = $this->tenant->runAsPlatform(
            'switch organization',
            fn (): MembershipModel => $this->resolveMembership($user, $organizationId),
        );

        // A switch is a password session arriving somewhere new, so it answers
        // to the same rule a password sign-in there would (ADR 0052): an
        // organization that requires single sign-on is entered through its
        // IdP, not through a door opened by another organization's password.
        if (! $this->passwordMayEnter($membership, $request)) {
            throw SingleSignOnRefused::required();
        }

        $result = $this->issueSession(
            $user,
            $membership,
            $request,
            switchedFrom: (string) $current->organization_id,
        );

        // After the new session exists, not before: a failure above leaves the
        // person where they were rather than signed out of both.
        $current->revoke('switched_organization');

        // Recorded in BOTH organizations: the one being left writes where the
        // person went, the one being entered (in issueSession) where they came
        // from. Each organization's audit view is its own, and a switch that
        // only one side could see would be half a record.
        $this->audit->record('auth.organization_switched_out', [
            'session_id' => $current->getKey(),
            'to_organization_id' => $organizationId,
        ], $request);

        return $result;
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
     * This is the reason sessions are opaque and server-side rather than JWTs:
     * revocation is immediate (docs/06 §1).
     *
     * Its docblock said "called on password change, MFA change, role change and
     * membership revocation" from Phase 1. It is called by NOTHING, and none of
     * those four flows exists yet. Worse, it took a `$reason`, wrote it into the
     * audit metadata, and left the row itself unexplained — so the CHECK added
     * with `revoked_reason` in Phase 7 would have failed the first time anybody
     * called it. **A constraint found a defect in code no test ever ran.**
     * ADR 0023 recorded it as owed. **Turning a second factor on or off is its
     * first caller** (ADR 0030), through `revokeOtherSessions` below — the MFA
     * change in that list of four. The password change is still owed, and the
     * remaining two with it.
     */
    public function revokeAllSessions(string $userId, string $reason, ?Request $request = null): int
    {
        return $this->revoke($userId, $reason, null, $request);
    }

    /**
     * Revoke every session for a user EXCEPT the one asking.
     *
     * Which is what every "your credentials changed" flow actually wants. A
     * person who has just enrolled a second factor, on the page in front of
     * them, should not be signed out of it for their trouble — the sessions
     * that opened WITHOUT the factor are the ones that should stop being
     * trusted, and this device is not one of them.
     */
    public function revokeOtherSessions(UserModel $user, string $reason, ?Request $request = null): int
    {
        $current = $user->currentAccessToken();

        return $this->revoke(
            (string) $user->getKey(),
            $reason,
            $current instanceof SessionModel ? (string) $current->getKey() : null,
            $request,
        );
    }

    private function revoke(string $userId, string $reason, ?string $exceptSessionId, ?Request $request): int
    {
        $query = SessionModel::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');

        if ($exceptSessionId !== null) {
            $query->whereKeyNot($exceptSessionId);
        }

        $count = $query->update(['revoked_at' => now(), 'revoked_reason' => $reason]);

        $this->audit->record('auth.session_revoked', [
            'user_id' => $userId,
            'reason' => $reason,
            'sessions_revoked' => $count,
        ], $request);

        return $count;
    }

    /**
     * This person's active membership in one organization, before any tenant
     * is bound — for a sign-in that already knows where it is going (SSO).
     */
    public function membershipIn(UserModel $user, string $organizationId): MembershipModel
    {
        return $this->resolveMembership($user, $organizationId);
    }

    /**
     * Where a correct password may take this person (ADR 0052).
     *
     * Oldest membership first, as before — but an organization that requires
     * single sign-on is skipped rather than chosen, so somebody who belongs to
     * one that does and one that does not lands in the one a password opens.
     * Only when every candidate refuses is the sign-in refused, and then with
     * the reason, because the password WAS right: the constant-time, same-
     * answer rule protects the password check, and that has already passed.
     */
    private function membershipForPassword(
        UserModel $user,
        ?string $organizationId,
        string $email,
        Request $request,
    ): MembershipModel {
        $query = MembershipModel::query()
            ->withoutGlobalScopes() // pre-tenant: this call is what CHOOSES the tenant
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $candidates = $query->orderBy('joined_at')->get();

        if ($candidates->isEmpty()) {
            throw new NoActiveMembership('You do not have access to any organization.');
        }

        foreach ($candidates as $membership) {
            if ($this->passwordMayEnter($membership, $request)) {
                return $membership;
            }
        }

        $this->audit->record('auth.login_failed', [
            'email' => $email,
            'reason' => 'sso_required',
        ], $request, actorUserId: (string) $user->getKey());

        throw SingleSignOnRefused::required();
    }

    /**
     * May a password open this membership's organization?
     *
     * Yes unless it requires single sign-on — and then still yes for the people
     * who administer the connection. That is the break-glass: an IdP that is
     * down, or a certificate pasted wrong, must not lock out the only people
     * who can fix it. Recorded every time it is used, in that organization's
     * audit log, because a break-glass nobody can see being broken is a back
     * door.
     *
     * Read in platform mode: a switch asks this about ANOTHER organization
     * while a tenant is bound, and under Row-Level Security the bound tenant
     * cannot see the other one's connection or grants (ADR 0051).
     */
    private function passwordMayEnter(MembershipModel $membership, Request $request): bool
    {
        $organizationId = (string) $membership->organization_id;

        return $this->tenant->runAsPlatform(
            'decide whether a password may enter an organization',
            function () use ($membership, $organizationId, $request): bool {
                if (! $this->sso->enforcedFor($organizationId)) {
                    return true;
                }

                if (! $this->permissions->has($membership, 'sso.manage')) {
                    return false;
                }

                $this->tenant->runFor(
                    $organizationId,
                    fn () => $this->audit->record('auth.sso_bypassed', [
                        'membership_id' => (string) $membership->getKey(),
                    ], $request, actorUserId: (string) $membership->user_id),
                );

                return true;
            },
        );
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
