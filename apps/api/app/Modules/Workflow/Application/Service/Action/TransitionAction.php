<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Work\Application\Service\WorkItemService;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;

/**
 * Move work automatically.
 *
 * The most dangerous action in the set: a transition triggers
 * `work_item.status_changed`, which is itself a rule trigger. Two rules that
 * transition each other's target state are a loop, and this is where it would
 * close.
 *
 * Two defences. The causation depth is threaded through, so the recursion guard
 * counts this cascade. And the move goes through WorkItemService like any
 * other, so transition legality and guards still apply — an automated move
 * cannot do something a person could not.
 */
final class TransitionAction implements WorkflowAction
{
    public function __construct(
        private readonly WorkItemService $workItems,
    ) {}

    /** {@inheritDoc} */
    public function execute(
        array $config,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        if ($subjectType !== 'work_item') {
            return ['skipped' => 'transition applies to work items only'];
        }

        /** @var WorkItemModel $item */
        $item = WorkItemModel::query()->findOrFail($subjectId);

        $target = $this->resolveState($item->workflow_id, (string) ($config['to_state'] ?? ''));

        if ($target === null) {
            return ['skipped' => 'target state not found in this workflow'];
        }

        // Already there. Without this check a redelivered job would record a
        // second transition and emit a second status-changed event, which is
        // how a benign retry becomes a cascade.
        if ((string) $item->workflow_state_id === (string) $target->id) {
            return ['skipped' => 'already in target state'];
        }

        $this->workItems->transition($item, (string) $target->id, [
            'cause' => 'rule',
            'causation_id' => $causationId,
            'causation_depth' => $depth,
        ]);

        return ['transitioned_to' => $target->key];
    }

    private function resolveState(string $workflowId, string $key): ?WorkflowStateModel
    {
        if ($key === '') {
            return null;
        }

        return WorkflowStateModel::query()
            ->where('workflow_id', $workflowId)
            ->where('key', $key)
            ->first();
    }
}
