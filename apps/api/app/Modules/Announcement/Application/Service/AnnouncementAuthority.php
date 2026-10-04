<?php

declare(strict_types=1);

namespace App\Modules\Announcement\Application\Service;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * May this person address this group? (ADR 0061)
 *
 * The one place the question is answered. The compose form is served its list
 * of audiences from {@see audiences()}, which asks {@see mayAddress()} of every
 * group, so the form cannot offer a group the API would then refuse.
 *
 * Three ways to be allowed, in the order they are cheapest to ask:
 *
 *  1. `announcement.publish` across the organization: any group.
 *  2. `announcement.publish` granted ON this team or department.
 *  3. `announcement.publish_own_group`, and the person heads the department
 *     (or one above it), or leads the team (or heads a department it is in).
 *
 * The third is a relationship, which docs/06 §2 warns about — but it is not
 * the `if (lead)` that warning is about. The PERMISSION decides whether a role
 * may speak for the groups it runs, and an organization can take it away from
 * managers or give it to anyone. The relationship only says WHICH groups those
 * are, from data the organization already maintains and can see.
 */
final class AnnouncementAuthority
{
    private ?MembershipModel $actor = null;

    private ?string $actorFor = null;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
        private readonly AnnouncementGroups $groups,
    ) {}

    /** Holds the organization-wide permission: may address, edit and remove anything. */
    public function speaksForEveryone(): bool
    {
        $actor = $this->actor();

        return $actor !== null && $this->permissions->has($actor, 'announcement.publish');
    }

    public function mayAddress(string $type, ?string $id): bool
    {
        $actor = $this->actor();

        if ($actor === null || ! $this->groups->exists($type, $id)) {
            return false;
        }

        if ($this->permissions->has($actor, 'announcement.publish')) {
            return true;
        }

        if ($type === 'organization' || $id === null) {
            return false;
        }

        if ($this->permissions->hasOnScope($actor, 'announcement.publish', $type, $id)) {
            return true;
        }

        if (! $this->permissions->has($actor, 'announcement.publish_own_group')) {
            return false;
        }

        return $type === 'team' ? $this->runsTeam($id) : $this->headsAnyOf($this->groups->departmentAndAbove($id));
    }

    /** May this person edit or remove this announcement? Its author may, always. */
    public function mayManage(string $authorMembershipId): bool
    {
        return $authorMembershipId === $this->tenant->membershipId() || $this->speaksForEveryone();
    }

    /**
     * Every group this person may address, for the compose form.
     *
     * @return list<array{type: string, id: string|null, name: string}>
     */
    public function audiences(): array
    {
        $organizationId = $this->tenant->organizationId();
        $audiences = [];

        if ($this->mayAddress('organization', null)) {
            $name = DB::table('organizations')->where('id', $organizationId)->value('name');
            $audiences[] = ['type' => 'organization', 'id' => null, 'name' => (string) $name];
        }

        foreach (['department' => 'departments', 'team' => 'teams'] as $type => $table) {
            $rows = DB::table($table)
                ->where('organization_id', $organizationId)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($rows as $row) {
                if ($this->mayAddress($type, (string) $row->id)) {
                    $audiences[] = ['type' => $type, 'id' => (string) $row->id, 'name' => (string) $row->name];
                }
            }
        }

        return $audiences;
    }

    private function runsTeam(string $teamId): bool
    {
        $me = $this->tenant->membershipId();

        $team = DB::table('teams')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $teamId)
            ->first(['lead_membership_id', 'department_id']);

        if ($team === null) {
            return false;
        }

        if ((string) $team->lead_membership_id === $me) {
            return true;
        }

        $leads = DB::table('team_members')
            ->where('team_id', $teamId)
            ->where('membership_id', $me)
            ->where('role', 'lead')
            ->whereNull('left_at')
            ->exists();

        return $leads || ($team->department_id !== null
            && $this->headsAnyOf($this->groups->departmentAndAbove((string) $team->department_id)));
    }

    /** @param list<string> $departmentIds */
    private function headsAnyOf(array $departmentIds): bool
    {
        return $departmentIds !== [] && DB::table('departments')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereIn('id', $departmentIds)
            ->where('head_membership_id', $this->tenant->membershipId())
            ->exists();
    }

    /** Keyed by membership id, for the reason TeamPolicy::actor() gives. */
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
