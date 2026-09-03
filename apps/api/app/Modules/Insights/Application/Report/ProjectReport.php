<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One project's work, as rows (docs/10, ADR 0011).
 *
 * Every item in the project, open or finished — the state category, the dates
 * and who holds it, which is what the health signals on
 * `/projects/{key}/overview` are computed from (ADR 0008). The signals
 * themselves are not repeated here: a verdict is a reading of the rows, and a
 * spreadsheet is where somebody goes to disagree with the reading.
 */
final class ProjectReport implements ReportBuilder
{
    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    public function columns(): array
    {
        return ['reference', 'title', 'state_category', 'due_at', 'assignee', 'completed_at'];
    }

    public function build(array $parameters): array
    {
        $key = mb_strtoupper((string) ($parameters['project'] ?? ''));

        $project = ProjectModel::query()
            ->visibleTo(
                $this->tenant->membershipId(),
                $this->permissions->has($this->acting->getOrFail(), 'project.view_all'),
            )
            ->where('key', $key)
            ->first();

        if ($project === null) {
            // Not "empty report": a project this reader cannot reach is one
            // they should not learn the existence of (ADR 0004).
            throw new ModelNotFoundException("No project [{$key}].");
        }

        $all = WorkItemModel::query()->where('project_id', $project->getKey());
        $total = $all->count();

        $visible = $this->visibility
            ->apply(WorkItemModel::query()->where('project_id', $project->getKey()))
            ->with(['assignments.membership.user:id,name'])
            ->orderByRaw('due_at asc nulls last')
            ->orderBy('reference')
            ->get();

        // array_values, not Collection::values(): `list<>` is a promise about
        // the keys, and an ordered collection's keys survive `all()`.
        $rows = array_values($visible->map(function (WorkItemModel $item): array {
            $assignee = $item->assignments
                ->first(fn ($assignment): bool => $assignment->role === 'assignee');

            return [
                (string) $item->reference,
                (string) $item->title,
                (string) $item->state_category,
                $item->due_at?->toIso8601String(),
                $assignee?->membership?->user?->name,
                $item->completed_at?->toIso8601String(),
            ];
        })->all());

        return ['rows' => $rows, 'hidden_count' => $total - count($rows)];
    }
}
