<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Query;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Project health, exactly as ADR 0008 defines it.
 *
 * Five named signals, each with its own verdict and the count behind it. There
 * is no weighted composite and no 0–100 number: the roadmap asked for
 * "explainable, not a black box", and a composite is precisely the thing that
 * cannot answer "why amber?" — the honest answer to that question is a list of
 * weights, which is not an answer anybody can act on.
 *
 * The overall verdict is the WORST signal, never an average. An average lets a
 * project that is on fire in one dimension and quiet in four read as mostly
 * fine, and loses the one fact worth having: which dimension.
 *
 * `unknown` is not `on_track`. A project with no end date is not on schedule;
 * it is a project with no schedule. This is the same rule as workload's
 * `time_off_hours: null` — zero is a claim, absent is an absence.
 *
 * The thresholds are constants here rather than literals in the SQL because
 * ADR 0008 promises an organization can eventually own them, and because a
 * number in a WHERE clause is a number nobody can find.
 */
final class ProjectHealthQuery
{
    /** The four verdicts a signal can reach. `unknown` is not `on_track`. */
    public const ON_TRACK = 'on_track';

    public const AT_RISK = 'at_risk';

    public const OFF_TRACK = 'off_track';

    public const UNKNOWN = 'unknown';

    /** Worst first. The overall verdict is the first status any signal reports. */
    private const SEVERITY = [self::OFF_TRACK, self::AT_RISK, self::ON_TRACK];

    /** An end date this close with open work left is a warning, not yet a miss. */
    private const SCHEDULE_WARNING_DAYS = 14;

    /** Overdue work at or above this share of open work is a systemic miss. */
    private const OVERDUE_OFF_TRACK_SHARE = 0.20;

    /** Blocked this long has stopped being a hand-off and become a stall. */
    private const BLOCKED_OFF_TRACK_DAYS = 7;

    public const ACTIVITY_AT_RISK_DAYS = 7;

    /**
     * Public because the drill-through's "stale" predicate has to be the same
     * number as the signal's, and two copies of a threshold is how a list comes
     * to disagree with the count that opened it.
     */
    public const ACTIVITY_OFF_TRACK_DAYS = 14;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function forProject(string $projectId, ?CarbonImmutable $endDate): array
    {
        $now = CarbonImmutable::now();
        $counts = $this->counts($projectId);
        $milestones = $this->milestones($projectId);

        $signals = [
            'schedule' => $this->schedule($endDate, $counts['open_count'], $now),
            'overdue_work' => $this->overdue($counts),
            'blocked_work' => $this->blocked($counts, $now),
            'milestones' => $this->milestoneSignal($milestones),
            'activity' => $this->activity($counts, $now),
        ];

        return [
            'status' => self::worst(array_column($signals, 'status')),

            // Computed from the work items rather than read from
            // `projects.progress_cache`, which nothing in the product writes —
            // the rollup job it was declared for was never built (ADR 0008).
            // A cached number that is always zero is worse than an honest query.
            'progress_percent' => $counts['countable_count'] === 0
                ? null
                : round(100 * $counts['done_count'] / $counts['countable_count'], 1),

            'open_count' => $counts['open_count'],
            'done_count' => $counts['done_count'],

            'signals' => $signals,

            // The milestones the signal is a verdict about. Few enough to be
            // the drill-through themselves rather than another round trip, and
            // there is no milestone page to link to yet.
            'past_due_milestones' => $milestones['past_due'],

            // Every threshold that produced a verdict above, so the page can
            // print the rule beside the number instead of restating it from
            // memory and drifting (ADR 0008).
            'thresholds' => [
                'schedule_warning_days' => self::SCHEDULE_WARNING_DAYS,
                'overdue_off_track_share' => self::OVERDUE_OFF_TRACK_SHARE,
                'blocked_off_track_days' => self::BLOCKED_OFF_TRACK_DAYS,
                'activity_at_risk_days' => self::ACTIVITY_AT_RISK_DAYS,
                'activity_off_track_days' => self::ACTIVITY_OFF_TRACK_DAYS,
            ],
        ];
    }

    /**
     * Everything the five signals count, in one round trip.
     *
     * One statement rather than five: this runs on a page load, and five
     * sequential queries for five numbers about the same rows is the shape
     * docs/11 §3 budgets against.
     *
     * @return array{item_count: int, open_count: int, done_count: int, countable_count: int, overdue_count: int, blocked_count: int, blocked_since: string|null, last_activity_at: string|null, stale_count: int}
     */
    private function counts(string $projectId): array
    {
        /**
         * @var object{item_count: int|string, open_count: int|string, done_count: int|string, countable_count: int|string, overdue_count: int|string, blocked_count: int|string, blocked_since: string|null, last_activity_at: string|null, stale_count: int|string}|null $row
         */
        $row = DB::selectOne(<<<'SQL'
            WITH items AS (
                SELECT w.id, w.state_category, w.due_at, w.created_at
                  FROM work_items w
                 WHERE w.organization_id = ?
                   AND w.project_id = ?
                   AND w.deleted_at IS NULL
            ),
            -- The most recent move OF ANY KIND per item, for staleness, and the
            -- most recent move into `blocked`, for how long a block has lasted.
            -- An item blocked, unblocked and blocked again is measured from the
            -- latest block: it is the current wait that is the problem.
            moves AS (
                SELECT t.work_item_id,
                       max(t.occurred_at) AS moved_at,
                       max(t.occurred_at) FILTER (WHERE t.to_category = 'blocked') AS blocked_at
                  FROM work_item_transitions t
                  JOIN items i ON i.id = t.work_item_id
                 WHERE t.organization_id = ?
                 GROUP BY t.work_item_id
            )
            SELECT
                count(*) AS item_count,

                count(*) FILTER (
                    WHERE i.state_category NOT IN ('done','cancelled')
                ) AS open_count,

                count(*) FILTER (WHERE i.state_category = 'done') AS done_count,

                -- Cancelled work is not progress and not remaining work, so it
                -- is out of the denominator entirely (ADR 0007's rule for
                -- throughput, applied to a percentage).
                count(*) FILTER (WHERE i.state_category <> 'cancelled') AS countable_count,

                count(*) FILTER (
                    WHERE i.state_category NOT IN ('done','cancelled')
                      AND i.due_at IS NOT NULL
                      AND i.due_at < now()
                ) AS overdue_count,

                count(*) FILTER (WHERE i.state_category = 'blocked') AS blocked_count,

                -- The OLDEST current block: the signal is about the longest
                -- wait, so the earliest of the latest-block timestamps wins.
                min(m.blocked_at) FILTER (WHERE i.state_category = 'blocked') AS blocked_since,

                max(m.moved_at) AS last_activity_at,

                count(*) FILTER (
                    WHERE i.state_category NOT IN ('done','cancelled')
                      AND coalesce(m.moved_at, i.created_at) < now() - (? || ' days')::interval
                ) AS stale_count

              FROM items i
         LEFT JOIN moves m ON m.work_item_id = i.id
        SQL, [
            $this->tenant->organizationId(),
            $projectId,
            $this->tenant->organizationId(),
            self::ACTIVITY_OFF_TRACK_DAYS,
        ]);

        return [
            'item_count' => (int) ($row->item_count ?? 0),
            'open_count' => (int) ($row->open_count ?? 0),
            'done_count' => (int) ($row->done_count ?? 0),
            'countable_count' => (int) ($row->countable_count ?? 0),
            'overdue_count' => (int) ($row->overdue_count ?? 0),
            'blocked_count' => (int) ($row->blocked_count ?? 0),
            'blocked_since' => $row->blocked_since ?? null,
            'last_activity_at' => $row->last_activity_at ?? null,
            'stale_count' => (int) ($row->stale_count ?? 0),
        ];
    }

    /**
     * @return array{total: int, missed: int, past_due: list<array{id: string, name: string, due_date: string|null, status: string}>}
     */
    private function milestones(string $projectId): array
    {
        /** @var list<object{id: string, name: string, due_date: string|null, status: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT id, name, due_date, status
              FROM milestones
             WHERE organization_id = ?
               AND project_id = ?
             ORDER BY due_date NULLS LAST, position
        SQL, [$this->tenant->organizationId(), $projectId]);

        $today = CarbonImmutable::now()->toDateString();
        $pastDue = [];
        $missed = 0;

        foreach ($rows as $row) {
            if ($row->status === 'missed') {
                $missed++;
            }

            // A completed milestone is never past due, however late it was:
            // the signal is about what is still outstanding, and a project
            // cannot work its way back to green if finished work keeps
            // counting against it.
            $outstanding = $row->status !== 'completed';
            $overdue = $row->due_date !== null && $row->due_date < $today;

            if ($row->status === 'missed' || ($outstanding && $overdue)) {
                $pastDue[] = [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'due_date' => $row->due_date === null ? null : (string) $row->due_date,
                    'status' => (string) $row->status,
                ];
            }
        }

        return ['total' => count($rows), 'missed' => $missed, 'past_due' => $pastDue];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(?CarbonImmutable $endDate, int $openCount, CarbonImmutable $now): array
    {
        if ($endDate === null) {
            return self::signal(self::UNKNOWN, ['end_date' => null, 'days_remaining' => null]);
        }

        $daysRemaining = (int) $now->startOfDay()->diffInDays($endDate->startOfDay(), false);

        // A date only becomes a verdict while there is work that has to fit
        // inside it. A project that finished last week is not late (ADR 0008).
        $status = match (true) {
            $openCount === 0 => self::ON_TRACK,
            $daysRemaining < 0 => self::OFF_TRACK,
            $daysRemaining <= self::SCHEDULE_WARNING_DAYS => self::AT_RISK,
            default => self::ON_TRACK,
        };

        return self::signal($status, [
            'end_date' => $endDate->toDateString(),
            'days_remaining' => $daysRemaining,
            'open_count' => $openCount,
        ]);
    }

    /**
     * @param  array{item_count: int, open_count: int, overdue_count: int}  $counts
     * @return array<string, mixed>
     */
    private function overdue(array $counts): array
    {
        // A project with no work items has nothing to be late. "Nothing is
        // overdue" would be true and useless — it is the same green an empty
        // project would get for every signal, and the sum of those greens is a
        // healthy verdict about a project that does not exist yet.
        //
        // A project whose work is all FINISHED is different, and stays
        // on_track: it really does have nothing overdue.
        if ($counts['item_count'] === 0) {
            return self::signal(self::UNKNOWN, ['count' => 0, 'open_count' => 0, 'share' => 0.0]);
        }

        $overdueCount = $counts['overdue_count'];
        $openCount = $counts['open_count'];

        $share = $openCount === 0 ? 0.0 : $overdueCount / $openCount;

        $status = match (true) {
            $overdueCount === 0 => self::ON_TRACK,
            $share >= self::OVERDUE_OFF_TRACK_SHARE => self::OFF_TRACK,
            default => self::AT_RISK,
        };

        return self::signal($status, [
            'count' => $overdueCount,
            'open_count' => $openCount,
            'share' => round($share, 3),
        ]);
    }

    /**
     * @param  array{item_count: int, blocked_count: int, blocked_since: string|null}  $counts
     * @return array<string, mixed>
     */
    private function blocked(array $counts, CarbonImmutable $now): array
    {
        // Same rule as overdue work: no items, nothing to judge.
        if ($counts['item_count'] === 0) {
            return self::signal(self::UNKNOWN, ['count' => 0, 'longest_days' => null]);
        }

        $blockedCount = $counts['blocked_count'];
        $blockedSince = $counts['blocked_since'];

        if ($blockedCount === 0) {
            return self::signal(self::ON_TRACK, ['count' => 0, 'longest_days' => null]);
        }

        // Null where an item sits in a blocked state with no transition
        // recording how it got there — imported or seeded work. Counted as
        // blocked, with no duration claimed for it.
        $longestDays = $blockedSince === null
            ? null
            : (int) CarbonImmutable::parse($blockedSince)->diffInDays($now);

        $status = $longestDays !== null && $longestDays >= self::BLOCKED_OFF_TRACK_DAYS
            ? self::OFF_TRACK
            : self::AT_RISK;

        return self::signal($status, [
            'count' => $blockedCount,
            'longest_days' => $longestDays,
        ]);
    }

    /**
     * @param  array{total: int, missed: int, past_due: list<array{id: string, name: string, due_date: string|null, status: string}>}  $milestones
     * @return array<string, mixed>
     */
    private function milestoneSignal(array $milestones): array
    {
        if ($milestones['total'] === 0) {
            return self::signal(self::UNKNOWN, ['count' => 0, 'past_due_count' => 0, 'missed_count' => 0]);
        }

        $pastDue = count($milestones['past_due']);

        $status = match (true) {
            $milestones['missed'] > 0, $pastDue >= 2 => self::OFF_TRACK,
            $pastDue === 1 => self::AT_RISK,
            default => self::ON_TRACK,
        };

        return self::signal($status, [
            'count' => $milestones['total'],
            'past_due_count' => $pastDue,
            'missed_count' => $milestones['missed'],
        ]);
    }

    /**
     * @param  array{open_count: int, last_activity_at: string|null, stale_count: int}  $counts
     * @return array<string, mixed>
     */
    private function activity(array $counts, CarbonImmutable $now): array
    {
        $lastActivityAt = $counts['last_activity_at'];
        $openCount = $counts['open_count'];

        if ($lastActivityAt === null) {
            return self::signal(self::UNKNOWN, [
                'last_activity_at' => null,
                'days_since' => null,
                'stale_count' => $counts['stale_count'],
            ]);
        }

        $daysSince = (int) CarbonImmutable::parse($lastActivityAt)->diffInDays($now);

        // Quiet is what a finished project is supposed to be. A signal that
        // flags one is a signal people learn to ignore — and then ignore on the
        // project where it mattered (ADR 0008).
        $status = match (true) {
            $openCount === 0 => self::ON_TRACK,
            $daysSince > self::ACTIVITY_OFF_TRACK_DAYS => self::OFF_TRACK,
            $daysSince >= self::ACTIVITY_AT_RISK_DAYS => self::AT_RISK,
            default => self::ON_TRACK,
        };

        return self::signal($status, [
            'last_activity_at' => CarbonImmutable::parse($lastActivityAt)->toIso8601String(),
            'days_since' => $daysSince,
            'open_count' => $openCount,
            // The items the drill-through lists: open work that has not moved
            // in `activity_off_track_days`. The project's last movement is one
            // item's; this is how many are sitting still behind it.
            'stale_count' => $counts['stale_count'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private static function signal(string $status, array $detail): array
    {
        return ['status' => $status] + $detail;
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function worst(array $statuses): string
    {
        foreach (self::SEVERITY as $status) {
            if (in_array($status, $statuses, strict: true)) {
                return $status;
            }
        }

        // Every signal returned `unknown`: a project nobody has set up, which
        // is a truthful answer rather than a green one.
        return self::UNKNOWN;
    }
}
