<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Work\Infrastructure\Eloquent\MilestoneModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A project's dated checkpoints (docs/02 §10, ADR 0056).
 *
 * The table shipped in Phase 2 and has been READ ever since — project health
 * judges a project by them, the calendar draws them — with no way to make one.
 * So the health signal said "no milestones" of every project that was not in
 * the seed, and the calendar's milestone source showed only the seed's.
 *
 * Every change is written to the PROJECT's activity, where a manager looks for
 * "who moved the launch date", and the verb names the milestone so the entry
 * reads without opening anything.
 */
final class Milestones
{
    public const STATUSES = ['open', 'at_risk', 'completed', 'missed'];

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * In date order, undated last, each with how much work it groups.
     *
     * @return Collection<int, MilestoneModel>
     */
    public function of(ProjectModel $project): Collection
    {
        return MilestoneModel::query()
            ->where('project_id', $project->getKey())
            ->withCount([
                'workItems as work_count',
                'workItems as open_work_count' => fn ($q) => $q->whereNotIn('state_category', ['done', 'cancelled']),
            ])
            ->orderByRaw('due_date NULLS LAST')
            ->orderBy('position')
            ->get();
    }

    public function create(ProjectModel $project, string $name, string $description, ?string $dueDate): MilestoneModel
    {
        return DB::transaction(function () use ($project, $name, $description, $dueDate): MilestoneModel {
            $position = (int) MilestoneModel::query()->where('project_id', $project->getKey())->max('position');

            $milestone = new MilestoneModel;
            $milestone->forceFill([
                'id' => MilestoneModel::newId(),
                'project_id' => $project->getKey(),
                'name' => $name,
                'description' => $description,
                'due_date' => $dueDate,
                'status' => 'open',
                'position' => $position + 1,
            ])->save();

            $this->activity->record('project', (string) $project->getKey(), 'milestone_added', [
                'milestone' => ['from' => null, 'to' => $name],
                'due_date' => ['from' => null, 'to' => $dueDate],
            ]);

            return $milestone;
        });
    }

    /**
     * Change what was sent, and nothing else.
     *
     * `completed_at` follows `status` — set on the way into completed, cleared
     * on the way out — because the table's CHECK couples them and a report
     * reading one without the other would be wrong either way. Reopening a
     * completed milestone is allowed: "we thought we had shipped it" happens.
     *
     * @param  array{name?: string, description?: string, due_date?: string|null, status?: string}  $changes
     */
    public function update(MilestoneModel $milestone, array $changes): MilestoneModel
    {
        return DB::transaction(function () use ($milestone, $changes): MilestoneModel {
            /** @var MilestoneModel $locked */
            $locked = MilestoneModel::query()->lockForUpdate()->findOrFail($milestone->getKey());

            $diff = [];

            foreach ($changes as $field => $value) {
                $before = $locked->getAttribute($field);

                if ($before instanceof CarbonImmutable) {
                    $before = $before->toDateString();
                }

                if ((string) $before !== (string) $value) {
                    $diff[$field] = ['from' => $before, 'to' => $value];
                }
            }

            if ($diff === []) {
                return $locked;
            }

            if (isset($changes['status'], $diff['status'])) {
                $changes['completed_at'] = $changes['status'] === 'completed' ? now() : null;
            }

            $locked->forceFill($changes)->save();

            $this->activity->record('project', (string) $locked->project_id, 'milestone_updated', [
                'milestone' => ['from' => $locked->name, 'to' => $locked->name],
            ] + $diff);

            return $locked;
        });
    }

    /**
     * Remove a milestone; its work stays, ungrouped.
     *
     * The work is detached HERE, before the delete, rather than left to the
     * foreign key's `ON DELETE SET NULL`. That key is composite —
     * `(organization_id, milestone_id)` — and Postgres's SET NULL without a
     * column list nulls BOTH columns, so the first milestone ever deleted with
     * work on it would have failed on `work_items.organization_id NOT NULL`.
     *
     * @return int how many items were in it
     */
    public function delete(MilestoneModel $milestone): int
    {
        return DB::transaction(function () use ($milestone): int {
            // Deleted items too — they still hold the key — and through the
            // base builder, so `updated_at` is not touched: losing a grouping
            // is not an edit to the work, and it must not restart the clock
            // closed work is archived by (ADR 0054).
            $detached = WorkItemModel::withTrashed()
                ->where('milestone_id', $milestone->getKey())
                ->toBase()
                ->update(['milestone_id' => null]);

            $milestone->delete();

            $this->activity->record('project', (string) $milestone->project_id, 'milestone_removed', [
                'milestone' => ['from' => $milestone->name, 'to' => null],
                'work_items' => ['from' => $detached, 'to' => 0],
            ]);

            return $detached;
        });
    }
}
