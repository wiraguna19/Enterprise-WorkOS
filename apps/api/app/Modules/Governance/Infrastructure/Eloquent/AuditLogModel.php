<?php

declare(strict_types=1);

namespace App\Modules\Governance\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;

/**
 * The security audit log, for READING.
 *
 * Written since Phase 1 by `AuditLogger` — every login failure, every
 * invitation, every role change — through the query builder, and read by
 * nothing but the partition command until Phase 7. `audit_log.view` was one of
 * the ten permissions `EveryPermissionMeansSomethingTest` found that nothing
 * consulted.
 *
 * A model rather than a raw query because cursor pagination is the default
 * across this API (docs/05 §3) and `CursorPage` takes a paginator. Nothing
 * writes through it: `AuditLogger` keeps its query-builder insert, which is
 * what lets it record events with NO organization at all.
 *
 * Tenant-scoped, and the consequence is deliberate: platform-mode rows carry a
 * null `organization_id` and are therefore invisible here. An organization's
 * audit view is its own events; the rows about the platform belong to whoever
 * runs it, on a screen this product does not have.
 *
 * The table is PARTITIONED BY RANGE (occurred_at) with a composite primary key
 * `(id, occurred_at)`. Eloquent cannot express that, and does not need to for
 * reads — every query here is scoped and ordered by `occurred_at`, which is
 * also the partition key, so the planner prunes.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $actor_user_id
 * @property string $actor_email_snapshot
 * @property string $event
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed> $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 */
final class AuditLogModel extends TenantModel
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
