<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Who reports to whom, answered once for the whole product.
 *
 * This walk lived privately inside `Work\WorkItemVisibility` until a second
 * caller needed it (ADR 0009). It belongs here: `employee_profiles` is
 * Organization's table, and a rule about the reporting hierarchy that lives in
 * two modules will eventually disagree with itself — one of them gains a depth
 * cap, or starts excluding ex-employees, and the two answers drift apart while
 * both look right in isolation.
 *
 * Transitive by design. A skip-level manager who cannot see their own
 * department's work cannot manage it, and a hierarchy that only counts direct
 * reports describes an org chart nobody actually works in.
 */
final class ReportingLine
{
    /**
     * How deep the walk goes.
     *
     * A CHECK constraint prevents a profile being its own manager, but not a
     * three-step cycle, and a cycle in this data would otherwise hang the
     * request rather than return a wrong answer. Six levels is deeper than any
     * reporting line this product is designed for.
     */
    private const MAX_DEPTH = 6;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Every membership beneath this one, at any depth. Excludes the person
     * themselves — this answers "who do I manage", not "who is in my subtree".
     *
     * @return list<string>
     */
    public function below(string $membershipId): array
    {
        /** @var list<object{membership_id: string}> $rows */
        $rows = DB::select(<<<SQL
            WITH RECURSIVE reports(profile_id, depth) AS (
                SELECT ep.id, 0
                  FROM employee_profiles ep
                 WHERE ep.membership_id = ? AND ep.organization_id = ?
                UNION ALL
                SELECT child.id, r.depth + 1
                  FROM employee_profiles child
                  JOIN reports r ON child.manager_profile_id = r.profile_id
                 WHERE r.depth < {$this->maxDepth()}
            )
            SELECT ep.membership_id
              FROM reports r
              JOIN employee_profiles ep ON ep.id = r.profile_id
             WHERE r.depth > 0
        SQL, [$membershipId, $this->tenant->organizationId()]);

        return array_values(
            array_map(static fn (object $row): string => (string) $row->membership_id, $rows)
        );
    }

    /** Interpolated rather than bound: a LIMIT-like literal, never user input. */
    private function maxDepth(): int
    {
        return self::MAX_DEPTH;
    }
}
