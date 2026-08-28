<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The user-facing timeline (docs/02 §8).
 *
 * Distinct from AuditLogger on purpose: different audience, different retention,
 * and this one cascades with its subject while audit never does.
 *
 * `actor_name_snapshot` is denormalised so a deactivated user's history still
 * reads "Sarah Chen submitted work" rather than a dangling ID.
 *
 * `correlation_id` groups every entry produced by one user action, so the UI can
 * collapse "changed 4 fields" into a single timeline row.
 */
final class ActivityLogger
{
    private ?string $correlationId = null;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function record(
        string $subjectType,
        string $subjectId,
        string $verb,
        array $changes = [],
        ?string $actorMembershipId = null,
        ?string $actorName = null,
    ): void {
        DB::table('activity_logs')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->organizationId(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            // hasMembership(), not hasTenant(). They are not the same question,
            // and TenantContext says so: a queued job bound with runFor() has an
            // organization but NO membership — it acts as the system, not as a
            // person. Asking hasTenant() there passes, membershipId() then
            // throws, and every rule action that logs activity fails inside the
            // queue while working perfectly in a request (docs/01 §6).
            'actor_membership_id' => $actorMembershipId
                ?? ($this->tenant->hasMembership() ? $this->tenant->membershipId() : null),
            'actor_name_snapshot' => $actorName ?? $this->resolveActorName(),
            'verb' => $verb,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'correlation_id' => $this->correlationId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Group everything written inside the callback under one correlation ID.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function grouped(callable $work): mixed
    {
        $previous = $this->correlationId;
        $this->correlationId = (string) new UuidV7;

        try {
            return $work();
        } finally {
            $this->correlationId = $previous;
        }
    }

    private function resolveActorName(): string
    {
        // Read from the table rather than through the authenticated model:
        // Governance may depend on Platform and nothing else (docs/04 §3), and
        // a name snapshot does not justify importing Identity.
        $userId = $this->tenant->userId();

        if ($userId === null) {
            return 'System';
        }

        return (string) (DB::table('users')->where('id', $userId)->value('name') ?? 'System');
    }
}
