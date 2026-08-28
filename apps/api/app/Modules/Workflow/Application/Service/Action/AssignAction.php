<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Work\Application\Service\AssignmentService;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Assign work automatically — typically routing a review to a manager.
 *
 * Idempotent by check: if the target already holds the role, nothing happens.
 * AssignmentService would otherwise throw AlreadyAssigned, and a rule that
 * throws on redelivery counts toward disabling itself for no reason.
 */
final class AssignAction implements WorkflowAction
{
    public function __construct(
        private readonly AssignmentService $assignments,
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
            return ['skipped' => 'assign applies to work items only'];
        }

        $role = (string) ($config['role'] ?? 'reviewer');
        $membershipId = $this->resolveTarget($config, $facts);

        if ($membershipId === null) {
            return ['skipped' => 'no target resolved'];
        }

        $alreadyHolds = DB::table('work_item_assignments')
            ->where('work_item_id', $subjectId)
            ->where('membership_id', $membershipId)
            ->where('role', $role)
            ->whereNull('unassigned_at')
            ->exists();

        if ($alreadyHolds) {
            return ['skipped' => 'already assigned'];
        }

        /** @var WorkItemModel $item */
        $item = WorkItemModel::query()->findOrFail($subjectId);

        $this->assignments->assign(
            $item,
            $membershipId,
            $role,
            reason: 'Assigned automatically by a workflow rule',
        );

        return ['assigned' => $membershipId, 'role' => $role];
    }

    /**
     * Resolve a role name to a membership.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $facts
     */
    private function resolveTarget(array $config, array $facts): ?string
    {
        $target = (string) ($config['to'] ?? 'manager_of_assignee');

        return match ($target) {
            'manager_of_assignee' => $this->managerOf($facts['assignee_membership_id'] ?? null),
            'project_owner' => $this->projectOwner($facts['project_id'] ?? null),
            'creator' => $facts['created_by_membership_id'] ?? null,
            // An explicit membership is allowed but discouraged; the rule
            // builder surfaces role targets first for the reason above.
            default => str_starts_with($target, 'membership:')
                ? substr($target, 11)
                : null,
        };
    }

    private function managerOf(?string $membershipId): ?string
    {
        if ($membershipId === null) {
            return null;
        }

        $managerProfileId = DB::table('employee_profiles')
            ->where('membership_id', $membershipId)
            ->value('manager_profile_id');

        if ($managerProfileId === null) {
            return null;
        }

        return DB::table('employee_profiles')
            ->where('id', $managerProfileId)
            ->value('membership_id');
    }

    private function projectOwner(?string $projectId): ?string
    {
        return $projectId === null
            ? null
            : DB::table('projects')->where('id', $projectId)->value('owner_membership_id');
    }
}
