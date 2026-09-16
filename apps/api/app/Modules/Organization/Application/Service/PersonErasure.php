<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Domain\Exception\ErasureRefused;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * "Delete my data" (docs/10 Phase 7, ADR 0022).
 *
 * Anonymisation, not deletion. The person goes out of the rows; the rows stay.
 * A hard delete would take their work items, comments, transitions and
 * approvals with them — which does not delete a person, it deletes a year of
 * the organization's history and silently changes every report computed from
 * it. What a subject is owed is that the data stops being about an identifiable
 * person, and that is what this does.
 *
 * Three things are load-bearing and easy to get wrong:
 *
 * - **The snapshots are the point.** `activity_logs.actor_name_snapshot`,
 *   `audit_logs.actor_email_snapshot` and the `payload.actor_name` inside other
 *   people's notifications exist precisely so history survives a person
 *   leaving. They are therefore where a person's name survives an erasure that
 *   only touched `users`, and an erasure that misses the ones it can reach is
 *   theatre.
 * - **The two logs cannot be reached, and that is correct.** `activity_logs`
 *   and `audit_logs` carry a BEFORE UPDATE OR DELETE trigger from Phase 1:
 *   append-only enforced by the database, because evidence the application can
 *   rewrite is not evidence. This service is an application. Their copies of
 *   the name expire by RETENTION instead (ADR 0021), and the interface says so
 *   rather than promising a clean sweep it cannot perform.
 * - **It must leave a record that it happened.** After it runs there is nothing
 *   in the row to say so, and "deleted person, no email" is indistinguishable
 *   from a broken import. `erased_at` and the audit event are that record.
 */
final class PersonErasure
{
    /** What a person's name becomes everywhere it was snapshotted. */
    public const TOMBSTONE = 'Deleted person';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function erase(MembershipModel $membership, Request $request): array
    {
        return DB::transaction(function () use ($membership, $request): array {
            $membership->refresh();

            if ($membership->erased_at !== null) {
                // Not an error in the database — every statement below is
                // idempotent — but running it twice would write a second audit
                // event claiming a second erasure, and the interface offering
                // the button again would be lying about what is left.
                throw new ErasureRefused(
                    'This person has already been erased.',
                    ['refusal' => 'already_erased'],
                );
            }

            $userId = (string) $membership->user_id;
            $this->refuseSharedAccount($membership, $userId);

            $this->stripOrganizationRecord($membership);
            $this->stripSnapshots($membership);
            $this->anonymiseAccount($userId);

            DB::table('memberships')->where('id', $membership->getKey())->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'erased_at' => now(),
                'erased_by' => $this->tenant->membershipId(),
                'updated_at' => now(),
            ]);

            // Versioned, not deleted: the erased person's own permissions are
            // gone with their roles, and anything cached would outlive them by
            // fifteen minutes.
            $this->permissions->invalidate((string) $membership->getKey());

            // The erasure's own record. It names the membership and the actor
            // and NOT the address — an audit event that quoted the erased email
            // would put it straight back into the table this just cleaned.
            $this->audit->record('person.erased', [
                'membership_id' => (string) $membership->getKey(),
                'user_id' => $userId,
            ], $request, targetType: 'membership', targetId: (string) $membership->getKey());

            return ['erased_at' => now()->toIso8601String()];
        });
    }

    /**
     * An account shared with another organization is not this one's to erase.
     *
     * Anonymising `users` would reach into a tenant whose administrator never
     * asked and may have a legal obligation to keep the record. Revoking access
     * here while leaving the name visible would be worse than refusing: it
     * would report "erased" over a person whose name is still on every comment.
     * So it is refused, named, and ADR 0022 records what is owed — a
     * platform-level erasure this product does not have.
     */
    private function refuseSharedAccount(MembershipModel $membership, string $userId): void
    {
        $elsewhere = DB::table('memberships')
            ->where('user_id', $userId)
            ->where('id', '!=', $membership->getKey())
            ->whereNull('erased_at')
            ->exists();

        if ($elsewhere) {
            throw new ErasureRefused(
                'This account is also a member of another organization, so it cannot be erased from here. Revoke their access instead.',
                ['refusal' => 'shared_account'],
            );
        }
    }

    /** Everything this organization holds ABOUT the person, as opposed to the
     *  work they did. */
    private function stripOrganizationRecord(MembershipModel $membership): void
    {
        $id = $membership->getKey();

        // Authority first: an erased person who kept a scoped grant would still
        // be an answer the resolver gives.
        DB::table('membership_roles')->where('membership_id', $id)->delete();
        DB::table('scoped_role_assignments')->where('membership_id', $id)->delete();
        DB::table('permission_denials')->where('membership_id', $id)->delete();

        // Their own inbox. Nobody else's notification is touched here — those
        // are other people's records, and only the name inside them is.
        DB::table('notifications')->where('membership_id', $id)->delete();

        DB::table('employee_profiles')->where('membership_id', $id)->update([
            'employee_number' => null,
            'job_title' => '',
            'work_location' => '',
            'hired_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * The places a name outlives its row — and the two that refuse to be
     * touched.
     *
     * Every snapshot column in this schema was added deliberately so history
     * would survive somebody leaving, which is exactly what makes them the last
     * hiding places of a name. Two of them are behind a database trigger:
     * `activity_logs` and `audit_logs` are append-only at the DATABASE level,
     * not by application discipline, and the first version of this method tried
     * to redact them anyway. Postgres refused with
     * `insufficient_privilege` — the schema defending a guarantee against the
     * application, which is what that trigger is for.
     *
     * It is not worked around. Disabling a trigger to honour an erasure would
     * trade a guarantee somebody wrote in Phase 1 — "the audit trail cannot be
     * rewritten, by anybody, including us" — for a redaction that retention
     * performs anyway: those rows age out of existence on the window in
     * `config/governance.php`. What the product owes instead is to SAY so, and
     * the erase screen does.
     */
    private function stripSnapshots(MembershipModel $membership): void
    {
        // Other people's notifications, where this person is the actor. The
        // payload is a rendered-safe snapshot so the inbox needs no joins —
        // and therefore holds the name. Not append-only: an inbox is a
        // convenience, not evidence.
        DB::table('notifications')
            ->where('actor_membership_id', $membership->getKey())
            ->update([
                'payload' => DB::raw(
                    "jsonb_set(payload, '{actor_name}', '\"".self::TOMBSTONE."\"'::jsonb, true)"
                ),
            ]);
    }

    /**
     * The shared account itself, reached only when this membership was its
     * last.
     *
     * The email has to stay UNIQUE and stay a valid address shape, so it
     * becomes a random one at `.invalid` — a TLD reserved by RFC 2606 precisely
     * so it can never be delivered to or registered.
     */
    private function anonymiseAccount(string $userId): void
    {
        // Read BEFORE the overwrite. The first version scrubbed `users` and
        // then looked the invitations up by "this user's email", which by then
        // was the tombstone — a statement that matched nothing and reported
        // success, leaving the real address in the one table outside `users`
        // that keeps a copy of it.
        $address = DB::table('users')->where('id', $userId)->value('email');

        if (is_string($address)) {
            DB::table('invitations')
                ->whereRaw('lower(email) = ?', [mb_strtolower($address)])
                ->update(['email' => 'erased-'.substr((string) new UuidV7, 0, 18).'@deleted.invalid']);
        }

        DB::table('users')->where('id', $userId)->update([
            'name' => self::TOMBSTONE,
            'email' => 'erased-'.substr((string) new UuidV7, 0, 18).'@deleted.invalid',
            'password_hash' => null,
            'avatar_path' => null,
            'mfa_secret_encrypted' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'deactivated_at' => now(),
            'erased_at' => now(),
            'updated_at' => now(),
        ]);

        // Sessions are deleted rather than revoked, unlike everywhere else in
        // this product. A revoked session row is kept so somebody can be told
        // WHY they were logged out; there is nobody left to tell, and the row
        // holds an IP address and a user agent.
        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
