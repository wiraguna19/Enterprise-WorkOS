<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\InvitationRefused;
use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Inviting somebody in, and letting them accept (docs/06 §1, docs/10 Phase 7).
 *
 * `person.invite` has been in the permission catalogue since Phase 1, granted
 * to managers and org admins, with **no endpoint behind it for seven phases** —
 * the longest-standing entry in this codebase's oldest defect class. The
 * `invitations` table was written in the same migration, with a token digest, an
 * expiry, a revocation column and a partial unique index over pending rows.
 * Everything was here except the feature.
 *
 * ## No email is sent, and that is a decision
 *
 * There is no mail layer in this product — no Mailable, no queue consumer for
 * one, nothing but Mailpit in the compose file. Rather than invent one inside
 * this slice, the invitation's link is returned ONCE, to the administrator who
 * created it, to pass on however they already talk to the person. The screen
 * says so out loud.
 *
 * That shape is not a placeholder for email; it is the honest version of what
 * the product can do today, and it is testable end to end. Email becomes a
 * delivery channel on top of it, not a rewrite of it (ADR 0017).
 *
 * ## The token is a secret
 *
 * Only `sha256(token)` is stored — credentials are digests in this codebase,
 * and an invitation token is a credential: it creates an account. The raw token
 * exists in exactly one response and then nowhere. Losing it means revoking and
 * inviting again, which is the same answer every password reset gives.
 */
final class Invitations
{
    /** Long enough to pass on by hand, short enough to be a decision. */
    private const VALID_FOR_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Issue an invitation, and return the link exactly once.
     *
     * @return array{id: string, token: string, email: string, expires_at: string}
     */
    public function invite(string $email, ?string $roleKey, Request $request): array
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($email, $roleKey, $request): array {
            $role = $roleKey === null ? null : $this->role($roleKey);

            if ($this->alreadyAMember($email)) {
                // Not an error in the invitations table — this one is about the
                // memberships table — and the most likely mistake somebody makes
                // on this form.
                throw new InvitationRefused(
                    'Somebody with that address is already in this organization.',
                    ['refusal' => 'already_a_member', 'email' => $email],
                );
            }

            if ($this->hasPendingInvitation($email)) {
                // The partial unique index would refuse this too, with a message
                // naming an index. Refusing here names the situation, and the
                // answer — revoke the old one — is a control on the same screen.
                throw new InvitationRefused(
                    'That address already has an invitation waiting. Revoke it first to issue a new one.',
                    ['refusal' => 'already_invited', 'email' => $email],
                );
            }

            $token = Str::random(48);
            $id = (string) new UuidV7;

            DB::table('invitations')->insert([
                'id' => $id,
                'organization_id' => $this->tenant->organizationId(),
                'email' => $email,
                'role_id' => $role?->id,
                'invited_by_membership_id' => $this->tenant->membershipId(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::VALID_FOR_DAYS),
            ]);

            // Audited, not merely logged as activity: an invitation is a way
            // into the organization, and who opened one is a security question
            // rather than a project one.
            $this->audit->record('invitation.issued', [
                'invitation_id' => $id,
                'email' => $email,
                'role' => $roleKey,
            ], $request);

            return [
                'id' => $id,
                // The one and only time this value exists outside a hash.
                'token' => $token,
                'email' => $email,
                'expires_at' => now()->addDays(self::VALID_FOR_DAYS)->toIso8601String(),
            ];
        });
    }

    /**
     * Invitations still waiting, with the ones that ran out shown as such.
     *
     * An expired invitation is kept and displayed rather than hidden: "I sent
     * that a fortnight ago and heard nothing" is answerable only if the row is
     * still there.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $rows = DB::table('invitations as i')
            ->leftJoin('roles as r', 'r.id', '=', 'i.role_id')
            ->where('i.organization_id', $this->tenant->organizationId())
            ->whereNull('i.accepted_at')
            ->whereNull('i.revoked_at')
            ->orderByDesc('i.created_at')
            ->get(['i.id', 'i.email', 'i.expires_at', 'i.created_at', 'r.key as role', 'r.name as role_name']);

        // `array_values`, not `values()->all()`. A mapped Collection keeps its
        // keys, and level 8 reads the result as "array with int keys" either
        // way — `Collection::values()->all()` is not a `list<>` to PHPStan, which
        // is a thing this codebase has now learned twice.
        return array_values($rows->map(fn (object $row): array => [
            'id' => $row->id,
            'email' => $row->email,
            'role' => $row->role,
            'role_name' => $row->role_name,
            'expires_at' => $row->expires_at,
            'created_at' => $row->created_at,
            'has_expired' => now()->greaterThan($row->expires_at),
        ])->all());
    }

    public function revoke(string $id, Request $request): void
    {
        $affected = DB::table('invitations')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($affected === 0) {
            throw new InvitationRefused(
                'That invitation is not waiting any more.',
                ['refusal' => 'not_pending'],
            );
        }

        $this->audit->record('invitation.revoked', ['invitation_id' => $id], $request);
    }

    /**
     * What the person holding this link is being offered.
     *
     * Public, and deliberately thin: the organization's name and the address it
     * was sent to, so somebody can tell whether they are in the right place. Not
     * the inviter, not the role's permissions, not anything about who else is
     * here — this is readable by anyone holding a string.
     *
     * @return array{organization: string, email: string}|null
     */
    public function preview(string $token): ?array
    {
        $row = $this->usable($token);

        if ($row === null) {
            return null;
        }

        $organization = DB::table('organizations')->where('id', $row->organization_id)->value('name');

        return [
            'organization' => is_string($organization) ? $organization : '',
            'email' => $row->email,
        ];
    }

    /**
     * Accept it: become a user if you are not one, and a member either way.
     *
     * Two branches, and the second is the one that is easy to get wrong. If no
     * user has this address, one is created with the password given here. **If a
     * user already exists, their password is not touched** — this is an
     * invitation to join an organization, not a password reset, and a flow that
     * quietly reset it would be an account takeover for anyone who could get an
     * invitation sent to a colleague's address.
     *
     * @return array{membership_id: string, organization_id: string}
     */
    public function accept(string $token, string $name, string $password, Request $request): array
    {
        return DB::transaction(function () use ($token, $name, $password, $request): array {
            // Locked for the rest of the transaction: two people opening the
            // same link at once would otherwise both pass the "still usable"
            // check and race to create the membership.
            $row = $this->usable($token, lock: true);

            if ($row === null) {
                // Wrong, expired, revoked, already used — one answer for all
                // four. Any difference between them is an oracle.
                throw new InvitationRefused(
                    'This invitation is no longer valid.',
                    ['refusal' => 'not_usable'],
                );
            }

            $user = UserModel::query()->whereRaw('lower(email) = ?', [$row->email])->first();

            if ($user === null) {
                $user = new UserModel;
                $user->forceFill([
                    'id' => UserModel::newId(),
                    'email' => $row->email,
                    'name' => $name,
                    'password_hash' => Hash::make($password),
                    'timezone' => 'Asia/Jakarta',
                    'locale' => 'en',
                    'is_platform_admin' => false,
                ])->save();
            }

            // Written with the query builder rather than the model, and this is
            // not a shortcut: `BelongsToOrganization` fills `organization_id`
            // from the tenant CONTEXT on create, and this endpoint is public —
            // there is no context, because there is no session yet. The model
            // would throw. The organization here comes from the invitation row,
            // which is the only authority that exists at this point.
            $membershipId = (string) new UuidV7;

            DB::table('memberships')->insert([
                'id' => $membershipId,
                'organization_id' => $row->organization_id,
                'user_id' => $user->getKey(),
                'status' => 'active',
                'invited_at' => $row->created_at,
                'joined_at' => now(),
            ]);

            if ($row->role_id !== null) {
                DB::table('membership_roles')->insert([
                    'organization_id' => $row->organization_id,
                    'membership_id' => $membershipId,
                    'role_id' => $row->role_id,
                ]);
            }

            DB::table('invitations')->where('id', $row->id)->update(['accepted_at' => now()]);

            $this->audit->record('invitation.accepted', [
                'invitation_id' => $row->id,
                'email' => $row->email,
                'membership_id' => $membershipId,
                'created_user' => $user->wasRecentlyCreated,
            ], $request);

            return [
                'membership_id' => $membershipId,
                'organization_id' => (string) $row->organization_id,
            ];
        });
    }

    /**
     * The row behind a token, if it is still good for something.
     *
     * Looked up by DIGEST. The raw token is never stored, so this is also the
     * only way to find it — there is no "list by token prefix" to leak.
     *
     * The return type is written as a SHAPE rather than `object`, because the
     * query builder hands back a bare `stdClass` and level 8 refuses to read a
     * property off one. Writing the shape says what this query selects, which
     * is the thing a reader wants anyway — and it breaks if a column is
     * dropped, where a cast would not.
     *
     * @return object{
     *     id: string,
     *     organization_id: string,
     *     email: string,
     *     role_id: string|null,
     *     created_at: string
     * }|null
     */
    private function usable(string $token, bool $lock = false): ?object
    {
        $query = DB::table('invitations')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        if ($lock) {
            $query->lockForUpdate();
        }

        /**
         * @var object{
         *     id: string,
         *     organization_id: string,
         *     email: string,
         *     role_id: string|null,
         *     created_at: string
         * }|null $row
         */
        $row = $query->first();

        return $row;
    }

    private function alreadyAMember(string $email): bool
    {
        return DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $this->tenant->organizationId())
            ->whereRaw('lower(u.email) = ?', [$email])
            ->whereNull('m.revoked_at')
            ->exists();
    }

    private function hasPendingInvitation(string $email): bool
    {
        return DB::table('invitations')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->exists();
    }

    private function role(string $key): RoleModel
    {
        $role = RoleModel::query()->where('key', $key)->first();

        if ($role === null) {
            throw new InvitationRefused(
                "This organization has no role keyed `{$key}`.",
                ['refusal' => 'unknown_role', 'role' => $key],
            );
        }

        return $role;
    }
}
