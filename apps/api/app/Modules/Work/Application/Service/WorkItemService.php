<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use App\Modules\Platform\Domain\Exception\ConcurrencyConflict;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Platform\Infrastructure\Realtime\Channel;
use App\Modules\Work\Domain\Event\WorkItemCreated;
use App\Modules\Work\Domain\Event\WorkItemStatusChanged;
use App\Modules\Work\Domain\Exception\CannotCloseBlockedWorkItem;
use App\Modules\Work\Domain\Exception\HierarchyTooDeep;
use App\Modules\Work\Domain\Exception\WorkItemHierarchyCycle;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Application\Service\TransitionService;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use Illuminate\Support\Facades\DB;

/**
 * The transaction boundary for work item writes (docs/01 §3).
 *
 * Controllers call these methods and nothing else. Every method here either
 * commits fully — row, activity entry, and recorded events — or changes
 * nothing at all.
 */
final class WorkItemService
{
    use RecordsDomainEvents;

    /** A tree, not a graph; deeper than this is a modelling mistake. */
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly PositionCalculator $positions,
        private readonly DependencyService $dependencies,
        private readonly TransitionService $transitions,
        private readonly AssignmentService $assignments,
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
        private readonly RealtimePublisher $realtime,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WorkItemModel
    {
        return $this->transactional(function () use ($attributes): WorkItemModel {
            $type = $attributes['type'] ?? 'task';
            $workflow = $this->resolveWorkflow($type, $attributes['project_id'] ?? null);
            $initial = $workflow->initialState();

            if ($initial === null) {
                throw new \LogicException(
                    "Workflow {$workflow->id} has no initial state; work could not be created."
                );
            }

            /** @var WorkItemModel|null $parent */
            $parent = isset($attributes['parent_id'])
                ? WorkItemModel::query()->findOrFail($attributes['parent_id'])
                : null;

            if ($parent !== null) {
                $this->assertHierarchyIsSane($parent);
            }

            $item = new WorkItemModel;
            $id = WorkItemModel::newId();

            $item->forceFill([
                'id' => $id,
                'type' => $type,
                // Generated inside the transaction under an advisory lock, so
                // two concurrent creates cannot claim the same ENG-142.
                'reference' => $this->references->next($attributes['project_id'] ?? null, $type),
                'title' => trim((string) $attributes['title']),
                'description' => $attributes['description'] ?? '',
                'project_id' => $attributes['project_id'] ?? null,
                // A subtask always lives in its parent's project; letting them
                // diverge produces work that is invisible from both.
                'parent_id' => $parent?->getKey(),
                'milestone_id' => $attributes['milestone_id'] ?? null,
                'created_by_membership_id' => $this->actorMembershipId(),
                'workflow_id' => $workflow->getKey(),
                'workflow_state_id' => $initial->getKey(),
                'state_category' => $initial->category,
                'priority' => $attributes['priority'] ?? 'medium',
                'start_date' => $attributes['start_date'] ?? null,
                'due_at' => $attributes['due_at'] ?? null,
                'estimate_hours' => $attributes['estimate_hours'] ?? null,
                // Set only by the recurrence materialiser. It is what lets
                // "stop creating these" find the work it already created, and
                // what tells a reader why an item nobody remembers requesting
                // exists (docs/03 §4).
                'recurrence_id' => $attributes['recurrence_id'] ?? null,
                'position' => $this->positions->forNewItem(
                    $attributes['project_id'] ?? null,
                    $initial->category,
                ),
            ]);

            if ($parent !== null) {
                $item->project_id = $parent->project_id;
            }

            $item->save();

            $this->activity->record('work_item', $id, 'created', [
                'title' => ['from' => null, 'to' => $item->title],
            ]);

            /*
             * Landing in the initial state IS a transition, and it needs a row.
             *
             * Without it, the first entry in an item's history is its move OUT
             * of backlog, so "how long did this sit before anyone started it" —
             * the most useful number in the whole report — has nothing to
             * subtract from. The seed already contained an opening row with a
             * NULL from_state; the code that creates real items did not, which
             * meant demo data and produced data had different shapes.
             *
             * cause = 'system': nobody chose this state, the workflow did.
             */
            $this->transitions->recordTransition(
                workItemId: $id,
                fromStateId: null,
                toStateId: (string) $initial->getKey(),
                fromCategory: null,
                toCategory: (string) $initial->category,
                cause: 'system',
            );

            /*
             * Create-and-assign is one step, not two.
             *
             * The request already accepts assignee_id; until now the service
             * dropped it on the floor, so the caller had to follow up with a
             * second POST — the single most common unnecessary click in tools
             * of this kind (docs/08 §4). Routed through AssignmentService so the
             * assignment row, the activity entry and the WorkItemAssigned event
             * are identical to any other assignment; a direct insert here would
             * be a second, silently diverging assignment path.
             */
            if (! empty($attributes['assignee_id'])) {
                $this->assignments->assign($item, (string) $attributes['assignee_id']);
            }

            $this->record(new WorkItemCreated(
                organizationId: $this->tenant->organizationId(),
                workItemId: $id,
                type: $type,
                projectId: $item->project_id,
                actorMembershipId: $this->actorMembershipId(),
            ));

            return $item;
        });
    }

    /**
     * Move a work item to another state.
     *
     * Phase 3 validates that the target state belongs to the item's workflow.
     * Phase 4 adds transition guards on top; this method is the single place
     * that will change, which is the reason it exists now rather than letting
     * controllers write `state_category` directly.
     *
     * @param  array<string, mixed>  $options
     */
    public function transition(
        WorkItemModel $item,
        string $toStateId,
        array $options = [],
    ): WorkItemModel {
        return $this->transactional(function () use ($item, $toStateId, $options): WorkItemModel {
            $this->assertNotStale($item, $options['lock_version'] ?? null);

            /** @var WorkItemModel $locked */
            $locked = WorkItemModel::query()->lockForUpdate()->findOrFail($item->getKey());

            $target = WorkflowStateModel::query()
                ->where('workflow_id', $locked->workflow_id)
                ->findOrFail($toStateId);

            $fromStateId = $locked->workflow_state_id;
            $fromCategory = $locked->state_category;

            if ($fromStateId === $target->getKey()) {
                return $locked;
            }

            // Phase 4: the move must be in the workflow, and its guard must
            // pass. Checked INSIDE the transaction that writes it, so a
            // concurrent workflow edit cannot slip between the check and the
            // write. A rule-caused move is checked identically — automation
            // cannot do something a person could not (docs/02 §7).
            $this->transitions->assertLegal(
                workflowId: (string) $locked->workflow_id,
                fromStateId: $fromStateId === null ? null : (string) $fromStateId,
                toStateId: (string) $target->getKey(),
                subjectFacts: $this->transitionFacts($locked),
                comment: $options['comment'] ?? null,
            );

            // Closing work that is still blocked by open dependencies is
            // refused — unless someone with the override permission supplies a
            // reason, which is recorded. Real organisations need the override;
            // a SILENT violation is what destroys trust in the data
            // (docs/02 §4.2).
            if (StateCategory::isClosed($target->category)) {
                $blockers = $this->dependencies->openBlockersFor($locked);

                if ($blockers !== [] && ($options['override_reason'] ?? null) === null) {
                    throw new CannotCloseBlockedWorkItem(
                        'This work is blocked by unfinished dependencies.',
                        ['blocked_by' => $blockers],
                    );
                }
            }

            $locked->forceFill([
                'workflow_state_id' => $target->getKey(),
                // Denormalised in the SAME transaction as the state change.
                // This is the discipline that keeps docs/12 §5 honest.
                'state_category' => $target->category,
                'completed_at' => $target->category === 'done' ? now() : null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            // The structured history cycle-time analytics read from. Append-
            // only, and separate from the activity log because one is data and
            // the other is narrative (docs/03 §4).
            $causationId = $this->transitions->recordTransition(
                workItemId: (string) $locked->getKey(),
                fromStateId: $fromStateId === null ? null : (string) $fromStateId,
                toStateId: (string) $target->getKey(),
                fromCategory: $fromCategory,
                toCategory: $target->category,
                cause: (string) ($options['cause'] ?? 'user'),
                causationId: $options['causation_id'] ?? null,
                causationDepth: (int) ($options['causation_depth'] ?? 0),
                overrideReason: $options['override_reason'] ?? null,
            );

            $this->activity->record('work_item', (string) $locked->getKey(), 'status_changed', [
                'state' => ['from' => $fromCategory, 'to' => $target->category],
            ]);

            $this->record(new WorkItemStatusChanged(
                organizationId: $this->tenant->organizationId(),
                workItemId: (string) $locked->getKey(),
                fromStateId: (string) $fromStateId,
                toStateId: (string) $target->getKey(),
                toCategory: $target->category,
                actorMembershipId: $this->actorMembershipId(),
                overrideReason: $options['override_reason'] ?? null,
            ));

            // Two channels, because two screens are watching the same fact from
            // different distances: whoever has the item open, and whoever is
            // looking at the board it sits on. Both get an id and refetch —
            // a board that trusted a payload would render a card its viewer may
            // not be allowed to see (docs/07 §8).
            $organizationId = $this->tenant->organizationId();

            $this->realtime->publish(
                Channel::workItem($organizationId, (string) $locked->getKey()),
                'work-item.status_changed',
                ['work_item_id' => (string) $locked->getKey()],
            );

            if ($locked->project_id !== null) {
                $this->realtime->publish(
                    Channel::board($organizationId, (string) $locked->project_id),
                    'work-item.status_changed',
                    ['work_item_id' => (string) $locked->getKey()],
                );
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(WorkItemModel $item, array $changes, ?int $lockVersion = null): WorkItemModel
    {
        return $this->transactional(function () use ($item, $changes, $lockVersion): WorkItemModel {
            $this->assertNotStale($item, $lockVersion);

            /** @var WorkItemModel $locked */
            $locked = WorkItemModel::query()->lockForUpdate()->findOrFail($item->getKey());

            $diff = [];

            foreach ($changes as $field => $value) {
                $before = $locked->getAttribute($field);

                if ($before instanceof \DateTimeInterface) {
                    $before = $before->format(DATE_ATOM);
                }

                if ((string) $before !== (string) $value) {
                    $diff[$field] = ['from' => $before, 'to' => $value];
                }
            }

            if ($diff === []) {
                return $locked;
            }

            $locked->forceFill($changes + ['lock_version' => $locked->lock_version + 1])->save();

            // One user action, one correlation ID — so the timeline can collapse
            // "changed 4 fields" into a single entry (docs/03 §6).
            $this->activity->grouped(function () use ($locked, $diff): void {
                $this->activity->record('work_item', (string) $locked->getKey(), 'updated', $diff);
            });

            return $locked;
        });
    }

    /**
     * Reorder within a board column, or move to another column.
     *
     * The position is fractional, so this writes ONE row. Renumbering a whole
     * column on every drag is the naive implementation and it is visibly slow
     * once a column holds a few hundred cards (docs/03 §3).
     */
    public function move(
        WorkItemModel $item,
        ?string $beforeId,
        ?string $afterId,
        ?string $toStateId = null,
    ): WorkItemModel {
        return $this->transactional(function () use ($item, $beforeId, $afterId, $toStateId): WorkItemModel {
            if ($toStateId !== null && $toStateId !== $item->workflow_state_id) {
                $item = $this->transition($item, $toStateId);
            }

            $position = $this->positions->between($beforeId, $afterId);

            $item->forceFill(['position' => $position])->save();

            return $item;
        });
    }

    /**
     * The facts a transition guard is evaluated against.
     *
     * `actor_roles` is what lets a guard say "only the reviewer may approve"
     * without the guard needing its own query — and it includes `creator`,
     * because "only the person who raised this may cancel it" is a rule real
     * organisations write.
     *
     * @return array<string, mixed>
     */
    private function transitionFacts(WorkItemModel $item): array
    {
        $membershipId = $this->tenant->hasMembership() ? $this->tenant->membershipId() : null;

        $roles = $membershipId === null ? [] : DB::table('work_item_assignments')
            ->where('work_item_id', $item->getKey())
            ->where('membership_id', $membershipId)
            ->whereNull('unassigned_at')
            ->pluck('role')
            ->all();

        if ($membershipId !== null && (string) $item->created_by_membership_id === $membershipId) {
            $roles[] = 'creator';
        }

        // A rule-caused move has no human actor. Granting it every role would
        // let automation bypass guards that exist precisely to stop it; the
        // system role is explicit instead.
        if ($membershipId === null) {
            $roles[] = 'system';
        }

        return [
            'type' => $item->type,
            'priority' => $item->priority,
            'state_category' => $item->state_category,
            'estimate_hours' => $item->estimate_hours,
            'actor_roles' => array_values(array_unique($roles)),
        ];
    }

    private function resolveWorkflow(string $type, ?string $projectId): WorkflowModel
    {
        if ($projectId !== null) {
            $projectWorkflowId = DB::table('projects')
                ->where('id', $projectId)
                ->value('workflow_id');

            if ($projectWorkflowId !== null) {
                /** @var WorkflowModel $workflow */
                $workflow = WorkflowModel::query()->findOrFail($projectWorkflowId);

                if ($workflow->applies_to_type === $type) {
                    return $workflow;
                }
            }
        }

        return WorkflowModel::query()
            ->where('applies_to_type', $type)
            ->where('is_default', true)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * A subtask's parent chain must stay a shallow tree.
     *
     * Depth is bounded because a five-level task tree is already past the point
     * where anyone can hold it in their head, and unbounded depth makes every
     * roll-up query recursive.
     */
    private function assertHierarchyIsSane(WorkItemModel $parent): void
    {
        $depth = 1;
        $cursor = $parent;
        $seen = [(string) $parent->getKey()];

        while ($cursor->parent_id !== null) {
            if (in_array((string) $cursor->parent_id, $seen, strict: true)) {
                throw new WorkItemHierarchyCycle('This would create a loop in the work item hierarchy.');
            }

            $seen[] = (string) $cursor->parent_id;
            $cursor = WorkItemModel::query()->findOrFail($cursor->parent_id);
            $depth++;

            if ($depth >= self::MAX_DEPTH) {
                throw new HierarchyTooDeep(
                    'Work items can be nested at most '.self::MAX_DEPTH.' levels deep.',
                    ['depth' => $depth],
                );
            }
        }
    }

    /**
     * Optimistic locking.
     *
     * The client sends the version it read. If the row has moved on, it gets a
     * 409 and the current state — never a silent overwrite of someone else's
     * edit (docs/03 §8).
     */
    private function assertNotStale(WorkItemModel $item, ?int $expected): void
    {
        if ($expected === null) {
            return;
        }

        $current = (int) DB::table('work_items')->where('id', $item->getKey())->value('lock_version');

        if ($current !== $expected) {
            throw new ConcurrencyConflict('This work item changed while you were editing it.', [
                'your_version' => $expected,
                'current_version' => $current,
            ]);
        }
    }

    /**
     * The person behind this change, or null when there is none.
     *
     * A rule-driven change runs in a job bound with runFor(): it has an
     * organization and no membership, and asking for one there throws. The
     * events carry null rather than a borrowed identity — attributing an
     * automated action to whoever happened to trigger it is how an audit trail
     * starts lying (docs/01 §6).
     */
    private function actorMembershipId(): ?string
    {
        return $this->tenant->hasMembership() ? $this->tenant->membershipId() : null;
    }
}
