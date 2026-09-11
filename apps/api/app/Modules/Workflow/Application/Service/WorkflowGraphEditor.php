<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Work\StateCategory;
use App\Modules\Workflow\Domain\Exception\GraphEditRefused;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowTransitionModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Editing a workflow that work is already moving through.
 *
 * The schema carries `version` and `superseded_by_id`, and neither is written
 * here. Copy-on-write versioning would mean mapping every state of the old
 * graph onto the new one and migrating the items in flight — most of that work
 * is the migration, and a wrong migration moves real work into the wrong
 * column. So edits happen IN PLACE, and the edits that would strand work or
 * rewrite what a report already counted are refused by name (ADR 0015).
 *
 * Every refusal here is checked inside the transaction that would perform the
 * write, with the workflow row locked. Checking first and writing after is the
 * shape that passes every test and fails under two administrators — the
 * department move learned the same lesson.
 */
final class WorkflowGraphEditor
{
    public function addState(WorkflowModel $workflow, string $key, string $label, string $category, string $color): WorkflowStateModel
    {
        return DB::transaction(function () use ($workflow, $key, $label, $category, $color): WorkflowStateModel {
            $this->lock($workflow);

            if (! in_array($category, StateCategory::ALL, strict: true)) {
                throw new GraphEditRefused(
                    'That is not one of the seven categories every state must map to.',
                    ['refusal' => 'unknown_category', 'category' => $category],
                );
            }

            if ($this->states($workflow)->where('key', $key)->exists()) {
                throw new GraphEditRefused(
                    "This workflow already has a state keyed `{$key}`.",
                    ['refusal' => 'duplicate_key', 'key' => $key],
                );
            }

            $state = new WorkflowStateModel;
            $state->forceFill([
                'id' => WorkflowStateModel::newId(),
                'workflow_id' => $workflow->getKey(),
                'key' => $key,
                'label' => $label,
                'category' => $category,
                'color' => $color,
                // Appended. `(workflow_id, position)` is UNIQUE, so inserting
                // into the middle means renumbering the rest inside a
                // constraint that does not defer — reordering is its own act
                // and is not offered here.
                'position' => (int) $this->states($workflow)->max('position') + 1,
                // A new state is reachable by nothing until somebody draws a
                // transition into it, and the screen says so. Creating it
                // wired up would be guessing at which edge was meant.
                'is_initial' => false,
                'is_terminal' => false,
                'requires_approval' => false,
            ])->save();

            return $state;
        });
    }

    /**
     * Rename a state, recolour it, or change whether it needs an approval.
     *
     * Not its CATEGORY and not its KEY, and both refusals are the point of this
     * class:
     *
     * - The category is what every list, board, report and overdue calculation
     *   reasons about. Changing it does not change the future; it changes what
     *   a finished quarter counted, silently, with no record that the meaning
     *   moved.
     * - The key is what rules match on (`to_state_key`). Renaming it leaves
     *   every rule that named it evaluating to false — forever, without error,
     *   which is the exact silence this product keeps paying for.
     *
     * The LABEL is free, and always was: it is the customer's word, and nothing
     * in the product reads it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateState(WorkflowStateModel $state, array $attributes): WorkflowStateModel
    {
        return DB::transaction(function () use ($state, $attributes): WorkflowStateModel {
            // Unrolled rather than looped over a field name: a variable
            // property access reads as clever and tells the type checker
            // nothing, and these are two different rules that happen to be
            // refused the same way.
            if (array_key_exists('category', $attributes) && $attributes['category'] !== $state->category) {
                throw new GraphEditRefused(
                    'A state\'s category cannot change: every report already counted work by it.',
                    ['refusal' => 'category_is_load_bearing', 'state_id' => $state->id],
                );
            }

            if (array_key_exists('key', $attributes) && $attributes['key'] !== $state->key) {
                throw new GraphEditRefused(
                    'A state\'s key cannot change: rules match on it, and a renamed key makes them stop matching without ever failing.',
                    ['refusal' => 'key_is_matched_by_rules', 'state_id' => $state->id],
                );
            }

            $state->forceFill(array_intersect_key($attributes, array_flip(['label', 'color', 'requires_approval'])))->save();

            return $state;
        });
    }

    /**
     * Remove a state, and only when removing it strands nothing.
     *
     * Work in the state is the obvious refusal. The edges are the one people
     * expect to be handled for them — the foreign key would cascade them away —
     * and that is exactly why it is refused instead: deleting a state should not
     * silently delete the moves through it, because the graph left behind is
     * not the one anybody looked at before pressing the button.
     */
    public function removeState(WorkflowStateModel $state): void
    {
        DB::transaction(function () use ($state): void {
            $holding = DB::table('work_items')
                ->where('workflow_state_id', $state->getKey())
                ->count();

            if ($holding > 0) {
                throw new GraphEditRefused(
                    "{$holding} work items are in this state. Move them first.",
                    ['refusal' => 'state_holds_work', 'work_items' => $holding],
                );
            }

            if ($state->is_initial) {
                throw new GraphEditRefused(
                    'This is where work starts. A workflow with no initial state can create nothing.',
                    ['refusal' => 'state_is_initial'],
                );
            }

            // Grouped, because the tenant scope has already added a WHERE:
            // `org = x AND from = s OR to = s` is not the question — and it is
            // the shape that reads correctly and answers across tenants.
            $edges = WorkflowTransitionModel::query()
                ->where(fn (Builder $query) => $query
                    ->where('from_state_id', $state->getKey())
                    ->orWhere('to_state_id', $state->getKey()))
                ->count();

            if ($edges > 0) {
                throw new GraphEditRefused(
                    "{$edges} moves lead into or out of this state. Remove them first.",
                    ['refusal' => 'state_has_transitions', 'transitions' => $edges],
                );
            }

            $state->delete();
        });
    }

    public function addTransition(
        WorkflowModel $workflow,
        ?string $fromStateId,
        string $toStateId,
        string $label,
        bool $requiresComment,
    ): WorkflowTransitionModel {
        return DB::transaction(function () use ($workflow, $fromStateId, $toStateId, $label, $requiresComment): WorkflowTransitionModel {
            $this->lock($workflow);

            foreach (array_filter([$fromStateId, $toStateId]) as $stateId) {
                if (! $this->states($workflow)->where('id', $stateId)->exists()) {
                    throw new GraphEditRefused(
                        'That state is not part of this workflow.',
                        ['refusal' => 'state_not_in_workflow', 'state_id' => $stateId],
                    );
                }
            }

            $duplicate = WorkflowTransitionModel::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('to_state_id', $toStateId)
                ->when(
                    $fromStateId === null,
                    fn (Builder $query) => $query->whereNull('from_state_id'),
                    fn (Builder $query) => $query->where('from_state_id', $fromStateId),
                )
                ->exists();

            if ($duplicate) {
                throw new GraphEditRefused(
                    'That move already exists.',
                    ['refusal' => 'duplicate_transition'],
                );
            }

            $transition = new WorkflowTransitionModel;
            $transition->forceFill([
                'id' => WorkflowTransitionModel::newId(),
                'workflow_id' => $workflow->getKey(),
                'from_state_id' => $fromStateId,
                'to_state_id' => $toStateId,
                'label' => $label,
                // No guard. A guard is a predicate about permissions and roles,
                // and a picker that composed one would be a second, smaller
                // authorization language beside docs/06 — worth building, and
                // not by accident from an edit form.
                'guard' => [],
                'requires_comment' => $requiresComment,
                'position' => (int) WorkflowTransitionModel::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->when(
                        $fromStateId === null,
                        fn (Builder $query) => $query->whereNull('from_state_id'),
                        fn (Builder $query) => $query->where('from_state_id', $fromStateId),
                    )
                    ->max('position') + 1,
            ])->save();

            return $transition;
        });
    }

    /**
     * Remove a move — unless it is the last way out of somewhere work is sitting.
     *
     * Without this check the graph stays valid and the WORK does not: an item
     * in a state with no out-edge cannot be advanced, cancelled, or unblocked
     * by anybody, and the only symptom is a person reporting that the buttons
     * are gone.
     */
    public function removeTransition(WorkflowTransitionModel $transition): void
    {
        DB::transaction(function () use ($transition): void {
            $from = $transition->from_state_id;

            if ($from !== null) {
                $stranded = DB::table('work_items')->where('workflow_state_id', $from)->count();

                $remaining = WorkflowTransitionModel::query()
                    ->where('workflow_id', $transition->workflow_id)
                    ->where('id', '!=', $transition->getKey())
                    ->where(fn (Builder $query) => $query->where('from_state_id', $from)->orWhereNull('from_state_id'))
                    ->count();

                if ($stranded > 0 && $remaining === 0) {
                    throw new GraphEditRefused(
                        "This is the last move out of a state holding {$stranded} work items. Removing it would leave them with nowhere to go.",
                        ['refusal' => 'would_strand_work', 'work_items' => $stranded],
                    );
                }
            }

            $transition->delete();
        });
    }

    /** @return Builder<WorkflowStateModel> */
    private function states(WorkflowModel $workflow): Builder
    {
        return WorkflowStateModel::query()->where('workflow_id', $workflow->getKey());
    }

    /**
     * The workflow row, locked for the rest of the transaction.
     *
     * Two administrators adding the last state at once is not a hypothetical in
     * a product with a shared settings screen, and `(workflow_id, position)` is
     * UNIQUE — without the lock the second insert fails on a constraint whose
     * message names neither of them.
     */
    private function lock(WorkflowModel $workflow): void
    {
        WorkflowModel::query()->lockForUpdate()->find($workflow->getKey());
    }
}
