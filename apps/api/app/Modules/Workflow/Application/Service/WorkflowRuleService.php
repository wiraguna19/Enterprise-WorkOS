<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;

/**
 * Writes for automation rules (ADR 0046).
 *
 * Both writes clear the failure count, and that is a decision, not a detail:
 * the engine disables a rule after five consecutive failures, so leaving the
 * count where it was would put a rule somebody has just fixed one failure away
 * from being switched off again — which reads as the fix not working.
 *
 * The count is cleared HERE rather than in the controller so that it cannot be
 * forgotten by a second caller. It already had two (create and update), and a
 * third — a rule re-enabled from anywhere else — would have had to remember.
 */
final class WorkflowRuleService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WorkflowRuleModel
    {
        $rule = new WorkflowRuleModel;

        $rule->forceFill($attributes + [
            'id' => WorkflowRuleModel::newId(),
            // Rules are organization-wide, not per workflow. A rule attached to
            // one workflow would have to be copied to every other, and copies
            // drift.
            'workflow_id' => null,
            'failure_count' => 0,
            'disabled_reason' => null,
        ])->save();

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(WorkflowRuleModel $rule, array $changes): WorkflowRuleModel
    {
        $rule->forceFill($changes + [
            'failure_count' => 0,
            'disabled_reason' => null,
        ])->save();

        return $rule;
    }
}
