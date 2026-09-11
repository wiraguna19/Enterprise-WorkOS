<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Policy;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;

/**
 * Per-record authorization for automation rules: layer 4 of docs/06 §2.
 *
 * Written WITH the first endpoint that authorizes a rule, not after it. Three
 * times in this codebase a controller has authorized a model whose policy did
 * not exist, and Gate's answer with no policy is deny: `PATCH /departments/{id}`
 * answered 403 to org admins for six phases, and nothing found it because
 * nothing called it. `PolicyRegistrationTest` now asks both halves of that
 * question, and this class is the answer to the second half for
 * `WorkflowRuleModel`.
 *
 * The tenant scope has already made another organization's rules unreachable,
 * so nothing here re-checks the organization.
 *
 * A rule belongs to the organization rather than to a person: there is no
 * "their own rule" here, and no author check. Automation that fires on
 * everybody's work is administered by whoever administers workflows — which is
 * exactly what `workflow.manage` says.
 */
final class WorkflowRulePolicy
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, WorkflowRuleModel $rule): bool
    {
        return $this->can('workflow.view');
    }

    public function create(UserModel $user): bool
    {
        return $this->can('workflow.manage');
    }

    /**
     * Editing and switching off are one permission.
     *
     * Deactivating a rule changes what the product does just as thoroughly as
     * rewriting its conditions — more, in the case of the rule that opens
     * approvals. Splitting them would offer a smaller-sounding grant that has
     * the larger effect.
     */
    public function update(UserModel $user, WorkflowRuleModel $rule): bool
    {
        return $this->can('workflow.manage');
    }

    private function can(string $permission): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }

    /**
     * Keyed by membership id, not merely memoized: one request can act as two
     * people — a console command binding a tenant per membership, or a test
     * that logs in twice — and a cache that ignored who it was for would answer
     * the second with the first one's permissions.
     */
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
