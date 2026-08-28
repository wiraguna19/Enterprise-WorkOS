<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Policy;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\ProjectMemberModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;

final class ProjectPolicy
{
    /**
     * The projects this actor owns or manages, fetched in ONE query the first
     * time it is asked.
     *
     * The directory renders a permissions block per project, so the previous
     * per-project `exists()` was one query per row — the N+1 that docs/11 §3
     * budgets against. Scoped to the request, so a role change is picked up on
     * the next one.
     *
     * @var list<string>|null
     */
    private ?array $managedProjectIds = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function view(UserModel $user, ProjectModel $project): bool
    {
        return $this->can('project.view');
    }

    public function update(UserModel $user, ProjectModel $project): bool
    {
        return $this->isOwnerOrManager($project) || $this->can('project.update');
    }

    public function delete(UserModel $user, ProjectModel $project): bool
    {
        return $this->can('project.delete');
    }

    public function archive(UserModel $user, ProjectModel $project): bool
    {
        return $this->isOwnerOrManager($project) || $this->can('project.archive');
    }

    public function manageMembers(UserModel $user, ProjectModel $project): bool
    {
        return $this->isOwnerOrManager($project) || $this->can('project.manage_members');
    }

    public function createWork(UserModel $user, ProjectModel $project): bool
    {
        return $this->can('work_item.create');
    }

    /**
     * A project role beats the absence of an org-wide permission.
     *
     * This is the seed of scoped permissions (docs/06 §2): someone can manage
     * THIS project without being able to manage projects generally.
     */
    private function isOwnerOrManager(ProjectModel $project): bool
    {
        $membershipId = $this->tenant->membershipId();

        if ((string) $project->owner_membership_id === $membershipId) {
            return true;
        }

        return in_array((string) $project->getKey(), $this->managedProjectIds(), strict: true);
    }

    /** @return list<string> */
    private function managedProjectIds(): array
    {
        if ($this->managedProjectIds !== null) {
            return $this->managedProjectIds;
        }

        return $this->managedProjectIds = array_values(
            ProjectMemberModel::query()
                ->where('membership_id', $this->tenant->membershipId())
                ->whereIn('role', ['owner', 'manager'])
                ->whereNull('removed_at')
                ->pluck('project_id')
                ->map(static fn ($id): string => (string) $id)
                ->all()
        );
    }

    private function can(string $permission): bool
    {
        $actor = $this->acting->get();

        return $actor !== null && $this->permissions->has($actor, $permission);
    }
}
