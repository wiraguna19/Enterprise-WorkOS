<?php

declare(strict_types=1);

namespace App\Modules\Announcement\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who is in a group, and which groups somebody is in (ADR 0061).
 *
 * Both questions are answered from the department tree's materialized path
 * (`/root/child/`): a department's ancestors are the ids in its own path, and
 * its descendants are the departments whose path starts with its path. A
 * sub-department is part of its department, so an announcement to Engineering
 * reaches Quality Assurance.
 */
final class AnnouncementGroups
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The teams and departments this person belongs to, for reading.
     *
     * Departments include every ancestor of the person's own department and of
     * the departments their teams belong to.
     *
     * @return array{teams: list<string>, departments: list<string>}
     */
    public function memberOf(string $membershipId): array
    {
        $organizationId = $this->tenant->organizationId();

        /** @var list<string> $teams */
        $teams = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.organization_id', $organizationId)
            ->where('team_members.membership_id', $membershipId)
            ->whereNull('team_members.left_at')
            ->whereNull('teams.archived_at')
            ->pluck('teams.id')
            ->map(strval(...))
            ->all();

        $own = DB::table('employee_profiles')
            ->where('organization_id', $organizationId)
            ->where('membership_id', $membershipId)
            ->value('department_id');

        $departmentIds = DB::table('teams')
            ->whereIn('id', $teams)
            ->whereNotNull('department_id')
            ->pluck('department_id')
            ->map(strval(...))
            ->all();

        if ($own !== null) {
            $departmentIds[] = (string) $own;
        }

        $paths = DB::table('departments')
            ->where('organization_id', $organizationId)
            ->whereIn('id', array_values(array_unique($departmentIds)))
            ->pluck('path');

        $departments = [];

        foreach ($paths as $path) {
            foreach ($this->idsIn((string) $path) as $id) {
                $departments[$id] = true;
            }
        }

        return ['teams' => array_values(array_unique($teams)), 'departments' => array_keys($departments)];
    }

    /**
     * Every active member an announcement to this group reaches today.
     *
     * @return list<string> membership ids
     */
    public function members(string $type, ?string $id): array
    {
        $organizationId = $this->tenant->organizationId();

        // People only. A service account is a member (ADR 0059), and an
        // announcement to "everyone" that notified an integration — and
        // counted it among those who have not read it — would be wrong twice.
        $query = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.organization_id', $organizationId)
            ->where('memberships.status', 'active')
            ->where('users.kind', 'person');

        if ($type === 'team') {
            $query->whereIn('memberships.id', DB::table('team_members')
                ->where('organization_id', $organizationId)
                ->where('team_id', $id)
                ->whereNull('left_at')
                ->select('membership_id'));
        }

        if ($type === 'department') {
            $departments = $this->departmentAndBelow((string) $id);

            $query->where(fn (Builder $where): Builder => $where
                ->whereIn('memberships.id', DB::table('employee_profiles')
                    ->where('organization_id', $organizationId)
                    ->whereIn('department_id', $departments)
                    ->select('membership_id'))
                ->orWhereIn('memberships.id', DB::table('team_members')
                    ->join('teams', 'teams.id', '=', 'team_members.team_id')
                    ->where('team_members.organization_id', $organizationId)
                    ->whereIn('teams.department_id', $departments)
                    ->whereNull('team_members.left_at')
                    ->select('team_members.membership_id')));
        }

        return array_values($query->pluck('memberships.id')->map(strval(...))->all());
    }

    /**
     * The department and every department below it.
     *
     * @return list<string>
     */
    public function departmentAndBelow(string $departmentId): array
    {
        $organizationId = $this->tenant->organizationId();

        $path = DB::table('departments')
            ->where('organization_id', $organizationId)
            ->where('id', $departmentId)
            ->value('path');

        if ($path === null) {
            return [];
        }

        return array_values(DB::table('departments')
            ->where('organization_id', $organizationId)
            ->where('path', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $path).'%')
            ->pluck('id')
            ->map(strval(...))
            ->all());
    }

    /**
     * The department and every department above it.
     *
     * @return list<string>
     */
    public function departmentAndAbove(string $departmentId): array
    {
        $path = DB::table('departments')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $departmentId)
            ->value('path');

        return $path === null ? [] : $this->idsIn((string) $path);
    }

    /** The name of a group, for showing who an announcement was for. */
    public function name(string $type, ?string $id): ?string
    {
        return match ($type) {
            'department' => $this->nameIn('departments', $id),
            'team' => $this->nameIn('teams', $id),
            default => null,
        };
    }

    /** Does this group exist, and is it still in use? */
    public function exists(string $type, ?string $id): bool
    {
        return match ($type) {
            'organization' => $id === null,
            'department', 'team' => $id !== null && DB::table($type === 'team' ? 'teams' : 'departments')
                ->where('organization_id', $this->tenant->organizationId())
                ->where('id', $id)
                ->whereNull('archived_at')
                ->exists(),
            default => false,
        };
    }

    private function nameIn(string $table, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $name = DB::table($table)
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->value('name');

        return $name === null ? null : (string) $name;
    }

    /** @return list<string> */
    private function idsIn(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));
    }
}
