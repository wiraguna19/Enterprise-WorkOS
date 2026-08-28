<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controller;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Application\Service\TransitionService;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use Illuminate\Support\Facades\DB;

final class WorkflowController extends ApiController
{
    public function __construct(
        private readonly TransitionService $transitions,
        private readonly WorkItemVisibility $visibility,
    ) {}

    public function index(): ApiResponse
    {
        $workflows = WorkflowModel::query()
            ->with('states')
            ->where('is_active', true)
            ->orderBy('applies_to_type')
            ->get();

        return $this->ok($workflows->map(fn (WorkflowModel $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'applies_to_type' => $w->applies_to_type,
            'version' => $w->version,
            'is_default' => $w->is_default,
            'states' => $w->states->map(fn (WorkflowStateModel $s): array => [
                'id' => $s->id,
                'key' => $s->key,
                'label' => $s->label,
                // The client renders from the CATEGORY, never the label — that
                // is what makes renaming a state safe (docs/02 §7).
                'category' => $s->category,
                'color' => $s->color,
                'is_initial' => $s->is_initial,
                'is_terminal' => $s->is_terminal,
                'requires_approval' => $s->requires_approval,
            ])->values(),
        ]));
    }

    /**
     * The moves available on THIS item, for THIS actor, right now.
     *
     * The status picker renders directly from this, which is the point: the set
     * the UI offers and the set the API accepts come from one query, so the
     * interface can never show a button that 403s (docs/07 §4).
     */
    public function availableTransitions(string $reference): ApiResponse
    {
        $item = $this->findVisible($reference);

        $this->authorize('view', $item);

        return $this->ok([
            'current' => [
                'id' => $item->workflow_state_id,
                'category' => $item->state_category,
            ],
            'transitions' => $this->transitions->availableFrom(
                (string) $item->workflow_id,
                (string) $item->workflow_state_id,
                $this->factsFor($item),
            ),
        ]);
    }

    /** The read-only rule list. The builder UI is Phase 7. */
    public function rules(): ApiResponse
    {
        $rules = WorkflowRuleModel::query()->orderBy('run_order')->get();

        return $this->ok($rules->map(fn (WorkflowRuleModel $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'trigger' => $r->trigger,
            'conditions' => $r->conditions,
            'actions' => $r->actions,
            'is_active' => $r->is_active,
            // Surfaced deliberately: a rule that has been silently failing is
            // the thing an administrator most needs to see.
            'health' => [
                'healthy' => $r->isHealthy(),
                'failure_count' => $r->failure_count,
                'disabled_reason' => $r->disabled_reason,
            ],
        ]));
    }

    /**
     * Why a rule did or did not fire.
     *
     * The first question anyone asks of any automation system, and
     * unanswerable without the run log (docs/02 §7).
     */
    public function ruleRuns(string $id): ApiResponse
    {
        $runs = DB::table('workflow_rule_runs')
            ->where('rule_id', $id)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'subject_type', 'subject_id', 'outcome', 'matched', 'actions_run', 'error', 'duration_ms', 'occurred_at']);

        return $this->ok($runs);
    }

    /** @return array<string, mixed> */
    private function factsFor(WorkItemModel $item): array
    {
        $membershipId = app(TenantContext::class)->membershipId();

        // The actor's roles ON THIS ITEM, so a guard can ask "only the reviewer
        // may approve" without a second query per transition.
        $roles = DB::table('work_item_assignments')
            ->where('work_item_id', $item->getKey())
            ->where('membership_id', $membershipId)
            ->whereNull('unassigned_at')
            ->pluck('role')
            ->all();

        if ((string) $item->created_by_membership_id === $membershipId) {
            $roles[] = 'creator';
        }

        return [
            'type' => $item->type,
            'priority' => $item->priority,
            'state_category' => $item->state_category,
            'estimate_hours' => $item->estimate_hours,
            'reference' => $item->reference,
            'title' => $item->title,
            'project_id' => $item->project_id,
            'workflow_id' => $item->workflow_id,
            'actor_roles' => array_values(array_unique($roles)),
        ];
    }

    private function findVisible(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}
