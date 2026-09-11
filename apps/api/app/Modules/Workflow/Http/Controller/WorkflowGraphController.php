<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controller;

use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Workflow\Application\Service\WorkflowGraphEditor;
use App\Modules\Workflow\Http\Request\CreateTransitionRequest;
use App\Modules\Workflow\Http\Request\SaveStateRequest;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowTransitionModel;

/**
 * Editing the graph work moves through (ADR 0015).
 *
 * Separate from `WorkflowController` because that one answers questions and
 * this one changes what the product will allow tomorrow — and because a
 * controller that both reads a catalogue and rewrites it grows past the point
 * where anyone checks which methods are authorized.
 *
 * Every method here authorizes the WORKFLOW, not the state or the transition:
 * a state is not a thing anybody owns separately from the graph it belongs to,
 * and asking about the parent is what stops a state id from another workflow
 * being authorized on its own terms.
 */
final class WorkflowGraphController extends ApiController
{
    public function __construct(
        private readonly WorkflowGraphEditor $editor,
    ) {}

    public function storeState(SaveStateRequest $request, string $workflowId): ApiResponse
    {
        $workflow = $this->workflow($workflowId);

        return $this->created($this->presentState($this->editor->addState(
            $workflow,
            $request->string('key')->toString(),
            $request->string('label')->toString(),
            $request->string('category')->toString(),
            $request->string('color', 'neutral')->toString(),
        )));
    }

    public function updateState(SaveStateRequest $request, string $workflowId, string $stateId): ApiResponse
    {
        $this->workflow($workflowId);

        return $this->ok($this->presentState($this->editor->updateState(
            $this->state($workflowId, $stateId),
            $request->validated(),
        )));
    }

    public function destroyState(string $workflowId, string $stateId): ApiResponse
    {
        $this->workflow($workflowId);

        $this->editor->removeState($this->state($workflowId, $stateId));

        return $this->noContent();
    }

    public function storeTransition(CreateTransitionRequest $request, string $workflowId): ApiResponse
    {
        $workflow = $this->workflow($workflowId);

        // Null is a VALUE here — "from anywhere" — so it is read as one rather
        // than passed through as whatever `input()` hands back.
        $from = $request->input('from_state_id');

        $transition = $this->editor->addTransition(
            $workflow,
            is_string($from) && $from !== '' ? $from : null,
            $request->string('to_state_id')->toString(),
            $request->string('label')->toString(),
            $request->boolean('requires_comment'),
        );

        return $this->created([
            'id' => $transition->id,
            'from_state_id' => $transition->from_state_id,
            'to_state_id' => $transition->to_state_id,
            'label' => $transition->label,
            'requires_comment' => $transition->requires_comment,
            'is_guarded' => false,
        ]);
    }

    public function destroyTransition(string $workflowId, string $transitionId): ApiResponse
    {
        $this->workflow($workflowId);

        /** @var WorkflowTransitionModel $transition */
        $transition = WorkflowTransitionModel::query()
            ->where('workflow_id', $workflowId)
            ->findOrFail($transitionId);

        $this->editor->removeTransition($transition);

        return $this->noContent();
    }

    /**
     * The same shape `GET /workflows` sends.
     *
     * Hand-written, and deliberately not the model: returning the row would
     * publish `organization_id` from a screen that has no business naming it,
     * and would tie the payload to the columns rather than to the contract.
     *
     * @return array<string, mixed>
     */
    private function presentState(WorkflowStateModel $state): array
    {
        return [
            'id' => $state->id,
            'key' => $state->key,
            'label' => $state->label,
            'category' => $state->category,
            'color' => $state->color,
            'is_initial' => $state->is_initial,
            'is_terminal' => $state->is_terminal,
            'requires_approval' => $state->requires_approval,
        ];
    }

    private function workflow(string $id): WorkflowModel
    {
        /** @var WorkflowModel $workflow */
        $workflow = WorkflowModel::query()->findOrFail($id);

        $this->authorize('update', $workflow);

        return $workflow;
    }

    /**
     * Scoped to the workflow in the path, not merely found by id.
     *
     * Without the `where`, a state id from another workflow would be edited
     * under an authorization decision made about this one — the tenant scope
     * would still refuse another organization's, which is precisely the kind of
     * near-miss that reads as safe.
     */
    private function state(string $workflowId, string $stateId): WorkflowStateModel
    {
        /** @var WorkflowStateModel $state */
        $state = WorkflowStateModel::query()
            ->where('workflow_id', $workflowId)
            ->findOrFail($stateId);

        return $state;
    }
}
