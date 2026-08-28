<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Organization\Domain\Exception\AlreadyOnTeam;
use App\Modules\Organization\Infrastructure\Eloquent\TeamMemberModel;
use App\Modules\Organization\Infrastructure\Eloquent\TeamModel;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;

final class TeamService
{
    use RecordsDomainEvents;

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    public function create(string $name, string $key, ?string $departmentId, ?string $leadMembershipId): TeamModel
    {
        return $this->transactional(function () use ($name, $key, $departmentId, $leadMembershipId): TeamModel {
            $team = new TeamModel;
            $id = TeamModel::newId();

            $team->forceFill([
                'id' => $id,
                'name' => $name,
                'key' => mb_strtoupper($key),
                'department_id' => $departmentId,
                'lead_membership_id' => $leadMembershipId,
            ])->save();

            // A team lead is a team member. Creating the team without the
            // membership row would leave the lead absent from every member list.
            if ($leadMembershipId !== null) {
                $this->addMember($team, $leadMembershipId, 'lead');
            }

            $this->activity->record('team', $id, 'created', [
                'name' => ['from' => null, 'to' => $name],
            ]);

            return $team;
        });
    }

    public function addMember(TeamModel $team, string $membershipId, string $role = 'member'): TeamMemberModel
    {
        $existing = TeamMemberModel::query()
            ->where('team_id', $team->getKey())
            ->where('membership_id', $membershipId)
            ->whereNull('left_at')
            ->exists();

        if ($existing) {
            throw new AlreadyOnTeam('That person is already on this team.', [
                'team_id' => $team->id,
                'membership_id' => $membershipId,
            ]);
        }

        $member = new TeamMemberModel;
        $member->forceFill([
            'id' => TeamMemberModel::newId(),
            'team_id' => $team->getKey(),
            'membership_id' => $membershipId,
            'role' => $role,
            'joined_at' => now(),
        ])->save();

        $this->activity->record('team', (string) $team->getKey(), 'member_added', [
            'membership_id' => ['from' => null, 'to' => $membershipId],
        ]);

        return $member;
    }

    /**
     * Removal closes the row; it never deletes it.
     *
     * Past team composition is what makes past workload and past work
     * attributable — deleting it silently rewrites history (docs/03 §2).
     */
    public function removeMember(TeamModel $team, string $membershipId): void
    {
        $member = TeamMemberModel::query()
            ->where('team_id', $team->getKey())
            ->where('membership_id', $membershipId)
            ->whereNull('left_at')
            ->firstOrFail();

        $member->forceFill(['left_at' => now()])->save();

        if ((string) $team->lead_membership_id === $membershipId) {
            $team->forceFill(['lead_membership_id' => null])->save();
        }

        $this->activity->record('team', (string) $team->getKey(), 'member_removed', [
            'membership_id' => ['from' => $membershipId, 'to' => null],
        ]);
    }
}
