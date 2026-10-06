<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * What a person asking for work to be created may not reach.
 *
 * One answer for every way a PERSON asks for work — a new item now, or a
 * recurring rule that will make one every week. Both used to check only that
 * a project or parent EXISTED in the organization, so either could put work on
 * a private board the caller cannot see and assign it to anybody, past the
 * `assign` rule every other path applies.
 *
 * Not applied when the SYSTEM creates work (the recurrence tick, templates
 * materialised by a rule): those were authorized when the person set them up.
 *
 * A project or parent the caller cannot see is reported as not found, exactly
 * as reading it would be.
 */
final class WorkCreationAccess
{
    public function __construct(
        private readonly ActingMembership $acting,
        private readonly PermissionResolver $permissions,
        private readonly WorkItemVisibility $visibility,
    ) {}

    /** @param array<string, mixed> $attributes project_id, parent_id, assignee_id */
    public function authorize(array $attributes): void
    {
        $actor = $this->acting->getOrFail();
        $me = (string) $actor->getKey();

        if (! empty($attributes['project_id'])) {
            $project = ProjectModel::query()
                ->visibleTo($me, $this->permissions->has($actor, 'project.view_all'))
                ->whereKey((string) $attributes['project_id'])
                ->firstOrFail();

            Gate::authorize('createWork', $project);
        }

        if (! empty($attributes['parent_id'])) {
            $parent = WorkItemModel::query()->whereKey((string) $attributes['parent_id']);
            $this->visibility->apply($parent);
            $parent->firstOrFail();
        }

        // Taking work yourself is not assigning it to somebody; anyone else
        // needs the permission AssignmentController and bulk edits require.
        $assignee = $attributes['assignee_id'] ?? null;

        if (! empty($assignee) && (string) $assignee !== $me && ! $this->permissions->has($actor, 'work_item.assign')) {
            throw new AuthorizationException('You cannot assign work to somebody else.');
        }
    }
}
