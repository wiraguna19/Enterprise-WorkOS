<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowModel;

/**
 * Per-record authorization for a workflow definition: layer 4 of docs/06 §2.
 *
 * Written with the first endpoint that authorizes a workflow, for the reason
 * `WorkflowRulePolicy` records: Gate's answer with no policy is deny, and this
 * codebase has shipped that three times as a 403 nobody could explain.
 *
 * `update` covers the whole graph — states and the moves between them. They are
 * one thing: a state with no way in is not a smaller change than a state with
 * one, and splitting the grant would invite giving somebody the half that can
 * still strand every item in a column.
 */
final class WorkflowPolicy
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, WorkflowModel $workflow): bool
    {
        return $this->can('workflow.view');
    }

    public function update(UserModel $user, WorkflowModel $workflow): bool
    {
        return $this->can('workflow.manage');
    }

    private function can(string $permission): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }

    /** Keyed by membership id: one request can act as two people. */
    private function actor(): ?MembershipModel
    {
        $membershipId = $this->tenant->membershipId();

        if ($this->actorFor !== $membershipId) {
            $this->actor = MembershipModel::query()->find($membershipId);
            $this->actorFor = $membershipId;
        }

        return $this->actor;
    }
}
