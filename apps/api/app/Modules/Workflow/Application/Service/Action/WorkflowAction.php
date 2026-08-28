<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

/**
 * One rule action.
 *
 * Every implementation must be IDEMPOTENT. The queue redelivers after a lost
 * ack, and an action that fires twice must not produce two notifications, two
 * approvals, or two assignments (docs/01 §4).
 *
 * `depth` is passed through untouched so anything an action triggers stays
 * inside the same causation chain the recursion guard counts.
 */
interface WorkflowAction
{
    /**
     * @param  array<string, mixed>  $config  the rule's `with` block
     * @param  array<string, mixed>  $facts  the trigger's subject facts
     * @return array<string, mixed> summary for the run log
     */
    public function execute(
        array $config,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array;
}
