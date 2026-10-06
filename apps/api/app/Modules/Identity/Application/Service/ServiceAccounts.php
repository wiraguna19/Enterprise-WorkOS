<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\ServiceAccountRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Members of an organization that are not people (ADR 0059).
 *
 * ADR 0049 made a token a session, acting as the person who made it — so an
 * integration died with its author's membership, and every change it made
 * was filed under their name. A service account is the answer that slice left
 * owed: a `users` row of kind `service`, with exactly one membership, holding
 * a role an administrator chose, reached only through tokens an administrator
 * issued.
 *
 * **A membership, on purpose.** Everything the product asks of an actor —
 * which permissions, which projects, which rows under Row-Level Security, whose
 * name goes in the activity log — is already answered for a membership. A
 * token belonging to the organization instead would have needed a second
 * answer to each, and the second answer is always the weaker door.
 *
 * **What it cannot be:** an administrator (ServiceAccountRefused::tooPowerful),
 * signed in to (no password, enforced by a CHECK), enrolled in a second factor,
 * or given work to hold (AssignmentService refuses it). Its tokens cannot
 * reach the routes that manage credentials, service accounts included
 * (LimitApiTokens).
 */
final class ServiceAccounts
{
    /** Roles a service account may not hold. */
    public const REFUSED_ROLES = ['org_admin'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ApiTokens $tokens,
        private readonly AuthenticationService $auth,
        private readonly PermissionResolver $permissions,
        private readonly AuditLogger $audit,
        private readonly AuthorityCeiling $ceiling,
    ) {}

    /**
     * Every service account here, active first, then by name.
     *
     * @return list<array{id: string, name: string, active: bool, role: array{key: string, name: string}|null, tokens: int, created_at: string}>
     */
    public function all(): array
    {
        $rows = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $this->tenant->organizationId())
            ->where('u.kind', 'service')
            ->orderByRaw("m.status = 'active' DESC")
            ->orderByRaw('lower(u.name)')
            ->get(['m.id', 'm.user_id', 'm.status', 'm.revoked_at', 'm.joined_at', 'u.name']);

        $out = [];

        foreach ($rows as $row) {
            $role = DB::table('membership_roles as mr')
                ->join('roles as r', 'r.id', '=', 'mr.role_id')
                ->where('mr.membership_id', $row->id)
                ->first(['r.key', 'r.name']);

            $out[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'active' => $row->status === 'active' && $row->revoked_at === null,
                'role' => $role === null ? null : ['key' => (string) $role->key, 'name' => (string) $role->name],
                'tokens' => SessionModel::query()
                    ->where('user_id', $row->user_id)
                    ->where('kind', 'api_token')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->count(),
                'created_at' => (string) $row->joined_at,
            ];
        }

        return $out;
    }

    public function create(string $name, string $roleKey, Request $request): MembershipModel
    {
        if (in_array($roleKey, self::REFUSED_ROLES, strict: true)) {
            throw ServiceAccountRefused::tooPowerful($roleKey);
        }

        $roleId = DB::table('roles')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('key', $roleKey)
            ->value('id');

        if ($roleId === null) {
            throw ServiceAccountRefused::unknownRole($roleKey);
        }

        // `org_admin` by name is not the whole of "too powerful": a custom role
        // can carry the same keys, and an administrator of less than
        // everything could otherwise make an account — and hold its token —
        // with more than they have themselves (AuthorityCeiling).
        $beyond = $this->ceiling->beyond((string) $roleId);

        if ($beyond !== []) {
            throw ServiceAccountRefused::beyondYourAuthority($roleKey, $beyond);
        }

        return DB::transaction(function () use ($name, $roleKey, $roleId, $request): MembershipModel {
            $user = new UserModel;
            $user->forceFill([
                'id' => UserModel::newId(),
                // Never delivered to and never signed in with: `.invalid` is
                // reserved by RFC 2606 for exactly this, and the address only
                // exists because `users.email` is NOT NULL and UNIQUE.
                'email' => 'svc-'.Str::lower((string) new UuidV7).'@service.invalid',
                'name' => Str::limit(trim($name), 160, ''),
                'password_hash' => null,
                'kind' => 'service',
                'timezone' => 'UTC',
                'locale' => 'en',
                'is_platform_admin' => false,
                'email_verified_at' => null,
            ])->save();

            $membership = new MembershipModel;
            $membership->forceFill([
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'user_id' => $user->getKey(),
                'status' => 'active',
                'invited_at' => now(),
                'joined_at' => now(),
            ])->save();

            DB::table('membership_roles')->insert([
                'organization_id' => $this->tenant->organizationId(),
                'membership_id' => $membership->getKey(),
                'role_id' => $roleId,
            ]);

            $this->audit->record('service_account.created', [
                'name' => $user->name,
                'role' => $roleKey,
            ], $request, targetType: 'membership', targetId: (string) $membership->getKey());

            return $membership;
        });
    }

    /**
     * A service account of THIS organization, active or not; 404 otherwise.
     */
    public function find(string $membershipId): MembershipModel
    {
        $membership = Str::isUuid($membershipId)
            ? MembershipModel::query()->with('user')->whereKey($membershipId)->first()
            : null;

        if (! $membership instanceof MembershipModel || $membership->user === null || ! $membership->user->isService()) {
            throw ServiceAccountRefused::notFound();
        }

        return $membership;
    }

    /** @return list<SessionModel> its live tokens, newest first */
    public function tokensOf(MembershipModel $account): array
    {
        return array_values(SessionModel::query()
            ->where('user_id', $account->user_id)
            ->where('organization_id', $this->tenant->organizationId())
            ->where('kind', 'api_token')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->all());
    }

    /**
     * A token for it, through the same `ApiTokens::issue()` a person's goes
     * through — same prefix, same digest-only storage, same limits.
     *
     * @return array{0: SessionModel, 1: string}
     */
    public function issueToken(MembershipModel $account, string $name, string $access, int $days, Request $request): array
    {
        if (! $account->isActive()) {
            throw ServiceAccountRefused::deactivated();
        }

        /** @var UserModel $user */
        $user = $account->user;

        [$token, $secret] = $this->tokens->issue($user, $name, $access, $days, $request);

        $this->audit->record('service_account.token_issued', [
            'token_id' => $token->getKey(),
            'name' => $token->name,
            'access' => $access,
            'expires_in_days' => $days,
        ], $request, targetType: 'membership', targetId: (string) $account->getKey());

        return [$token, $secret];
    }

    public function revokeToken(MembershipModel $account, string $tokenId, Request $request): void
    {
        /** @var UserModel $user */
        $user = $account->user;

        $this->tokens->revoke($user, $tokenId, $request);
    }

    /**
     * Switch it off: the membership ends and every token with it, on their
     * next request. Not a delete — the activity log names this account as
     * the actor of what it did, and that record outlives it.
     */
    public function deactivate(MembershipModel $account, Request $request): void
    {
        if (! $account->isActive()) {
            return;
        }

        $account->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();

        $ended = $this->auth->revokeAllSessions((string) $account->user_id, 'service_account_deactivated', $request);

        $this->permissions->invalidate((string) $account->getKey());

        $this->audit->record('service_account.deactivated', [
            'tokens_ended' => $ended,
        ], $request, targetType: 'membership', targetId: (string) $account->getKey());
    }
}
