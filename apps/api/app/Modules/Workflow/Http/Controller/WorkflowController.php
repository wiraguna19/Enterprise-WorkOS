<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controller;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Application\Service\ManualRuleRun;
use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Application\Service\TransitionService;
use App\Modules\Workflow\Application\Service\WorkflowRuleService;
use App\Modules\Workflow\Http\Request\RunRuleRequest;
use App\Modules\Workflow\Http\Request\SaveRuleRequest;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowTransitionModel;
use Illuminate\Support\Facades\DB;

final class WorkflowController extends ApiController
{
    public function __construct(
        private readonly TransitionService $transitions,
        private readonly WorkItemVisibility $visibility,
        private readonly ManualRuleRun $manual,
        // Not `$rules`: this controller already answers `rules()`, and a
        // property that shadows a method reads as a typo forever after.
        private readonly WorkflowRuleService $ruleEditor,
    ) {}

    public function index(): ApiResponse
    {
        $workflows = WorkflowModel::query()
            ->with(['states', 'transitions'])
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
            // The edges, not just the nodes. Without them this endpoint
            // describes a list of statuses and calls it a workflow — and the
            // one question an administrator opens this screen to answer is
            // which moves are legal, which is a property of the edges alone.
            'transitions' => $w->transitions->map(fn (WorkflowTransitionModel $t): array => [
                'id' => $t->id,
                // NULL means "from anywhere" (docs/02 §7). Sent as null rather
                // than expanded into one edge per state: the fan-out is the
                // fact, and a reader that expands it loses the ability to say
                // so in words.
                'from_state_id' => $t->from_state_id,
                'to_state_id' => $t->to_state_id,
                'label' => $t->label,
                'requires_comment' => $t->requires_comment,
                // The guard itself is not sent — it is a predicate over facts
                // this endpoint does not have, so rendering it beside a graph
                // would invite reading it as a promise. Whether one EXISTS is
                // the part that changes how the edge should be drawn.
                'is_guarded' => $t->guard !== [],
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

    public function rules(): ApiResponse
    {
        $rules = WorkflowRuleModel::query()->orderBy('run_order')->get();

        return $this->ok($rules->map($this->presentRule(...)));
    }

    /**
     * Everything a rule may legally say, from the code that implements it.
     *
     * The builder has to offer a list of triggers, operators and actions, and
     * a list kept in the interface is a copy — this codebase has paid for that
     * four times. Offering an action the executor cannot run is the worst of
     * them: it throws inside a queued job, hours later.
     */
    public function vocabulary(): ApiResponse
    {
        return $this->ok(RuleVocabulary::all());
    }

    public function storeRule(SaveRuleRequest $request): ApiResponse
    {
        $this->authorize('create', WorkflowRuleModel::class);

        $rule = $this->ruleEditor->create([
            'name' => $request->string('name')->toString(),
            'description' => $request->string('description')->toString(),
            'trigger' => $request->string('trigger')->toString(),
            'conditions' => $request->array('conditions'),
            'actions' => array_values($request->array('actions')),
            'is_active' => $request->boolean('is_active', true),
            'run_order' => $request->integer('run_order'),
        ]);

        return $this->created($this->presentRule($rule));
    }

    /**
     * Editing a rule, and switching one off.
     *
     * Both are this one endpoint because both are `workflow.manage`: turning
     * off the rule that opens approvals changes what the product does more
     * thoroughly than rewriting its conditions.
     *
     * **Re-activating clears the failure count**, and so does any edit. The
     * engine disables a rule after five consecutive failures; leaving the count
     * where it was would put a rule somebody has just fixed one failure away
     * from being switched off again, which reads as the fix not working.
     */
    public function updateRule(SaveRuleRequest $request, string $id): ApiResponse
    {
        /** @var WorkflowRuleModel $rule */
        $rule = WorkflowRuleModel::query()->findOrFail($id);

        $this->authorize('update', $rule);

        $changes = [];

        // Only what was sent. A form that posts every field turns "switch this
        // off" into a rewrite of the conditions with whatever the client last
        // read — and a client reading a stale rule would silently revert an
        // edit made a minute earlier.
        foreach (['name', 'description', 'trigger', 'conditions', 'actions', 'is_active', 'run_order'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->input($field);
            }
        }

        $this->ruleEditor->update($rule, $changes);

        return $this->ok($this->presentRule($rule));
    }

    /** @return array<string, mixed> */
    private function presentRule(WorkflowRuleModel $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'trigger' => $rule->trigger,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
            'is_active' => $rule->is_active,
            // Surfaced deliberately: a rule that has been silently failing is
            // the thing an administrator most needs to see.
            'health' => [
                'healthy' => $rule->isHealthy(),
                'failure_count' => $rule->failure_count,
                'disabled_reason' => $rule->disabled_reason,
            ],
        ];
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

    /**
     * Try a rule against one work item (ADR 0035).
     *
     * Preview by default, and the body has to say `apply: true` to make it
     * happen — a route whose default behaviour changes live data is a route
     * somebody triggers by exploring.
     */
    public function runRule(RunRuleRequest $request, string $id): ApiResponse
    {
        /** @var WorkflowRuleModel $rule */
        $rule = WorkflowRuleModel::query()->findOrFail($id);

        $workItemId = $this->manual->resolveWorkItem($request->reference());

        if ($workItemId === null) {
            // 404 for a reference in another tenant as well as for one that
            // does not exist: "that item is real but not yours" is a fact
            // nobody outside the organization is owed (docs/05 §3).
            abort(404, 'No work item with that reference.');
        }

        if (! $request->shouldApply()) {
            return $this->ok($this->manual->preview($rule, $workItemId) + ['applied' => false]);
        }

        $result = $this->manual->run(
            $rule,
            $workItemId,
            app(TenantContext::class)->membershipId(),
            $request,
        );

        return $this->ok($result + ['applied' => true]);
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
