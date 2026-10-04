<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Kpi;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Organization\Application\Query\ReportingLine;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and keep which KPI (ADR 0062). The one place it is decided: the
 * list, the detail, every write and the form's list of subjects all ask here.
 *
 * Group KPIs:
 *   see    — `kpi.view`, and for a project, being able to see the project
 *   manage — `kpi.manage`, organization-wide or granted on the subject
 *   record — the same as manage
 *
 * A KPI about one person (ADR 0062, "Per person") — governed by no
 * permission, only by the reporting line:
 *   see    — the person, and anyone above them in it
 *   manage — anyone above them; never the person
 *   record — the person; never anyone else
 */
final class KpiAuthority
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    /** @var array<string, bool> */
    private array $projects = [];

    /** @var list<string>|null */
    private ?array $below = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
        private readonly ReportingLine $reportingLine,
    ) {}

    public function maySee(string $subjectType, string $subjectId): bool
    {
        if ($subjectType === 'person') {
            return $subjectId === $this->tenant->membershipId() || $this->isAbove($subjectId);
        }

        $actor = $this->actor();

        if ($actor === null || ! $this->permissions->has($actor, 'kpi.view')) {
            return false;
        }

        return $subjectType !== 'project' || $this->canSeeProject($subjectId);
    }

    public function mayManage(string $subjectType, string $subjectId): bool
    {
        if ($subjectType === 'person') {
            return $this->isAbove($subjectId);
        }

        $actor = $this->actor();

        if ($actor === null || ! in_array($subjectType, ['team', 'department', 'project'], true)) {
            return false;
        }

        if ($subjectType === 'project' && ! $this->canSeeProject($subjectId)) {
            return false;
        }

        return $this->permissions->hasOnScope($actor, 'kpi.manage', $subjectType, $subjectId);
    }

    /**
     * Every subject this person may give a KPI, for the form.
     *
     * @return list<array{type: string, id: string, name: string}>
     */
    public function subjects(): array
    {
        $organizationId = $this->tenant->organizationId();
        $subjects = [];

        foreach (['department' => 'departments', 'team' => 'teams', 'project' => 'projects'] as $type => $table) {
            $rows = DB::table($table)
                ->where('organization_id', $organizationId)
                ->whereNull('archived_at')
                ->when($table === 'projects', fn (Builder $query): Builder => $query->whereNull('deleted_at'))
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($rows as $row) {
                if ($this->mayManage($type, (string) $row->id)) {
                    $subjects[] = ['type' => $type, 'id' => (string) $row->id, 'name' => (string) $row->name];
                }
            }
        }

        return $subjects;
    }

    /** May this person enter a manual KPI's values? */
    public function mayRecord(string $subjectType, string $subjectId): bool
    {
        return $subjectType === 'person'
            ? $subjectId === $this->tenant->membershipId()
            : $this->mayManage($subjectType, $subjectId);
    }

    /** Somewhere above them in the reporting line, at any depth. */
    private function isAbove(string $membershipId): bool
    {
        $this->actor();
        $this->below ??= $this->reportingLine->below($this->tenant->membershipId());

        return in_array($membershipId, $this->below, true);
    }

    private function canSeeProject(string $projectId): bool
    {
        $actor = $this->actor();

        if ($actor === null) {
            return false;
        }

        return $this->projects[$projectId] ??= ProjectModel::query()
            ->whereKey($projectId)
            ->visibleTo($this->tenant->membershipId(), $this->permissions->has($actor, 'project.view_all'))
            ->exists();
    }

    /** Keyed by membership id, for the reason TeamPolicy::actor() gives. */
    private function actor(): ?MembershipModel
    {
        $membershipId = $this->tenant->membershipId();

        if ($this->actorFor !== $membershipId) {
            $this->actor = MembershipModel::query()->find($membershipId);
            $this->actorFor = $membershipId;
            $this->projects = [];
            $this->below = null;
        }

        return $this->actor;
    }
}
