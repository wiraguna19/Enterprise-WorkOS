<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Query;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which recurring rules a person may see.
 *
 * A rule is a template of future work — title, description, project, assignee
 * — so it is visible to whoever could see the work it makes: its author, the
 * person it assigns, and anyone who can see its project. Before this, every
 * rule in the organization, private projects included, was listed to anyone
 * with `work_item.view`, and the calendar showed every rule's title to
 * everybody.
 */
final class RecurrenceVisibility
{
    public function __construct(
        private readonly ActingMembership $acting,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @param  Builder<RecurrenceModel>  $query
     * @return Builder<RecurrenceModel>
     */
    public function apply(Builder $query): Builder
    {
        $actor = $this->acting->getOrFail();
        $me = (string) $actor->getKey();

        $projects = ProjectModel::query()
            ->visibleTo($me, $this->permissions->has($actor, 'project.view_all'))
            ->select('id');

        return $query->where(fn (Builder $where): Builder => $where
            ->where('created_by_membership_id', $me)
            ->orWhereRaw("template->>'assignee_id' = ?", [$me])
            ->orWhereIn(DB::raw("(template->>'project_id')::uuid"), $projects));
    }

    /** May this person stop the rule? Its author, or someone who may assign work and can see it. */
    public function mayStop(RecurrenceModel $recurrence): bool
    {
        $actor = $this->acting->getOrFail();

        return $recurrence->created_by_membership_id === (string) $actor->getKey()
            || $this->permissions->has($actor, 'work_item.assign');
    }
}
