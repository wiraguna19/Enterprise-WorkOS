<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reading the timeline back (docs/02 §8).
 *
 * `ActivityLogger` has written to `activity_logs` since Phase 2 and nothing has
 * ever read it: `activity.view` existed as a permission with no endpoint behind
 * it, and the only assertions on the trail were made directly against the table
 * inside the test suite. A log nobody can read is a log nobody can check, which
 * is the whole reason it is written.
 *
 * This class answers "what happened to this subject" and nothing else. It does
 * NOT decide who may ask — the caller owns the subject and owns its visibility,
 * because Governance cannot see Work and should not learn to.
 */
final class ActivityFeed
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * One subject's timeline, newest first, collapsed by correlation.
     *
     * `$since` is not an optional nicety: `activity_logs` is
     * `PARTITION BY RANGE (occurred_at)`, and a query with no bound on that
     * column reads every partition there has ever been. The caller knows a
     * bound that is both exact and free — nothing can have happened to a
     * subject before the subject existed — so it passes the subject's own
     * creation time and the planner prunes to the months that can hold rows.
     *
     * @return list<array{
     *     correlation_id: string,
     *     occurred_at: string,
     *     actor: string,
     *     actor_membership_id: string|null,
     *     entries: list<array{verb: string, changes: array<string, mixed>}>
     * }>
     */
    public function forSubject(
        string $subjectType,
        string $subjectId,
        \DateTimeInterface $since,
        int $limit = 100,
    ): array {
        $rows = DB::table('activity_logs')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'verb', 'changes', 'correlation_id', 'occurred_at', 'actor_membership_id', 'actor_name_snapshot']);

        $groups = [];

        foreach ($rows as $row) {
            // One user action can write several rows — a rename that also moved
            // the item, a transition that reassigned it. They carry the same
            // correlation id precisely so a timeline can show one event with
            // its parts, rather than four rows a second apart that read like
            // four separate decisions.
            //
            // Rows written outside a `grouped()` block have no correlation id;
            // each is its own group, keyed by its own id so they never merge.
            $key = $row->correlation_id ?? 'single:'.$row->id;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'correlation_id' => (string) $key,
                    'occurred_at' => (string) $row->occurred_at,
                    'actor' => (string) $row->actor_name_snapshot,
                    'actor_membership_id' => $row->actor_membership_id === null
                        ? null
                        : (string) $row->actor_membership_id,
                    'entries' => [],
                ];
            }

            /** @var array<string, mixed> $changes */
            $changes = json_decode((string) $row->changes, true, 512, JSON_THROW_ON_ERROR) ?: [];

            $groups[$key]['entries'][] = [
                'verb' => (string) $row->verb,
                'changes' => $changes,
            ];
        }

        return array_values($groups);
    }
}
