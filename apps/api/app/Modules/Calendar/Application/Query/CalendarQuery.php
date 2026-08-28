<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Query;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RRule\RRule;
use Throwable;

/**
 * One calendar out of three sources (docs/10, Phase 5).
 *
 * A calendar is not a fourth kind of record — it is a view over things that
 * already have dates. So nothing is stored here: each source is queried through
 * the same rule that governs it everywhere else, and a person's calendar can
 * never show them a deadline they could not have found by other means
 * (docs/06 §2).
 *
 * The third source is the interesting one. Recurring work that has not been
 * created yet has no row to read, so its occurrences are EXPANDED from the rule
 * for the window being viewed — and marked `is_projected`, because "this will
 * appear on Monday" and "this exists and is due Monday" are different facts and
 * a calendar that blurs them teaches people to distrust it.
 */
final class CalendarQuery
{
    public const SOURCES = ['work', 'milestones', 'recurring'];

    /**
     * A projection window is bounded regardless of what the caller asks for: a
     * daily rule over a two-year range is 730 events nobody reads.
     */
    private const MAX_PROJECTED_PER_RULE = 60;

    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>  $sources
     * @return list<array<string, mixed>>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to, array $sources): array
    {
        $events = [];

        if (in_array('work', $sources, strict: true)) {
            $events = [...$events, ...$this->workItems($from, $to)];
        }

        if (in_array('milestones', $sources, strict: true)) {
            $events = [...$events, ...$this->milestones($from, $to)];
        }

        if (in_array('recurring', $sources, strict: true)) {
            $events = [...$events, ...$this->projectedOccurrences($from, $to)];
        }

        // usort sorts in place and keeps the list a list; array_values here
        // would be a no-op that reads as a safeguard.
        usort($events, static fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function workItems(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = WorkItemModel::query()
            ->with(['project:id,key,name', 'state:id,key,label,category,color'])
            ->whereNull('deleted_at')
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to]);

        $this->visibility->apply($query);

        return array_values($query->orderBy('due_at')->limit(500)->get()
            ->map(fn (WorkItemModel $item): array => [
                'type' => 'work_item',
                'id' => (string) $item->getKey(),
                'title' => (string) $item->title,
                'reference' => (string) $item->reference,
                // Deadlines have a time; a calendar that renders them all-day
                // loses "Friday 17:00", which is the part people plan around
                // (docs/03 §4).
                'starts_at' => $item->due_at?->toIso8601String(),
                'all_day' => false,
                'project' => $item->project?->key,
                'state' => $item->state?->category,
                'is_projected' => false,
            ])->all());
    }

    /**
     * Milestones are dates, not moments: `due_date` is a `date` column, and
     * rendering it as midnight in the viewer's zone would move it a day for
     * anyone east or west of the server.
     *
     * @return list<array<string, mixed>>
     */
    private function milestones(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('milestones as m')
            ->join('projects as p', function ($join): void {
                $join->on('p.id', '=', 'm.project_id')
                    ->whereColumn('p.organization_id', 'm.organization_id');
            })
            ->where('m.organization_id', $this->tenant->organizationId())
            // Only the project side is filtered: milestones are deleted for
            // real (docs/03), so there is no m.deleted_at to check, and asking
            // for one is an undefined-column error rather than an empty result.
            ->whereNull('p.deleted_at')
            ->whereNotNull('m.due_date')
            ->whereBetween('m.due_date', [$from->toDateString(), $to->toDateString()])
            // Visibility, through the project: a milestone in a private project
            // is as invisible as the project (docs/06 §2).
            ->whereIn('m.project_id', $this->visibleProjectIds())
            ->orderBy('m.due_date')
            ->limit(200)
            ->get(['m.id', 'm.name', 'm.due_date', 'm.status', 'p.key as project_key']);

        return array_values($rows->map(static fn (object $row): array => [
            'type' => 'milestone',
            'id' => (string) $row->id,
            'title' => (string) $row->name,
            'reference' => null,
            'starts_at' => (string) $row->due_date,
            'all_day' => true,
            'project' => $row->project_key,
            'state' => (string) $row->status,
            'is_projected' => false,
        ])->all());
    }

    /**
     * Work that does not exist yet.
     *
     * @return list<array<string, mixed>>
     */
    private function projectedOccurrences(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = [];

        /** @var iterable<RecurrenceModel> $recurrences */
        $recurrences = RecurrenceModel::query()->where('is_active', true)->get();

        foreach ($recurrences as $recurrence) {
            try {
                $rule = new RRule($recurrence->rrule, $recurrence->created_at->toDateTime());
            } catch (Throwable) {
                // A rule the library cannot read is the materialiser's problem
                // to report, not the calendar's to crash on.
                continue;
            }

            $count = 0;

            foreach ($rule as $occurrence) {
                $at = CarbonImmutable::instance($occurrence);

                if ($at->lessThan($from)) {
                    continue;
                }

                if ($at->greaterThan($to) || $count >= self::MAX_PROJECTED_PER_RULE) {
                    break;
                }

                if ($recurrence->ends_at !== null && $at->greaterThan($recurrence->ends_at)) {
                    break;
                }

                $template = $recurrence->template;

                $events[] = [
                    'type' => 'recurring',
                    'id' => (string) $recurrence->getKey().'@'.$at->toDateString(),
                    'title' => (string) ($template['title'] ?? 'Recurring work'),
                    'reference' => null,
                    'starts_at' => $at->toIso8601String(),
                    'all_day' => false,
                    'project' => null,
                    'state' => null,
                    // The honest label: this has not been created yet.
                    'is_projected' => true,
                ];

                $count++;
            }
        }

        return $events;
    }

    /**
     * The same scope the project directory uses — not a list derived from work
     * the person happens to see.
     *
     * Deriving it would be subtly wrong in both directions: a project whose
     * work is all assigned elsewhere would vanish from the calendar, and a
     * private project the person can reach one item in would surrender its
     * milestones (docs/06 §2).
     *
     * @return list<string>
     */
    private function visibleProjectIds(): array
    {
        $actor = $this->acting->getOrFail();

        return array_values(array_map(strval(...), ProjectModel::query()
            ->visibleTo(
                $this->tenant->membershipId(),
                $this->permissions->has($actor, 'project.view_all'),
            )
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all()));
    }
}
