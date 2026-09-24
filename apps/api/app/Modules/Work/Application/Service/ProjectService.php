<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Exception\ConcurrencyConflict;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Domain\Exception\ProjectMembershipRefused;
use App\Modules\Work\Infrastructure\Eloquent\PinnedProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectMemberModel;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The transaction boundary for project writes (docs/01 §3, ADR 0040).
 *
 * This class did not exist, and neither did the endpoints behind it. A project
 * could be CREATED and never corrected: `PATCH /projects/{key}` was not a route
 * and `update()` was not a method, so a typo in a project's name was permanent
 * and its dates could not move. Four permissions — `project.update`,
 * `project.archive`, `project.delete`, `project.manage_members` — were seeded
 * in Phase 1 and granted to roles, and `ProjectPolicy` answers all four, with
 * nothing anywhere asking.
 *
 * That policy is what hid it. `EveryPermissionMeansSomethingTest` asks whether
 * a permission is CONSULTED, and a policy method consults it — so four
 * permissions with no endpoint had an alibi, the same way a write once hid
 * behind the read on its own path.
 *
 * Archiving lives here too, and it is a separate act from updating on purpose:
 * one fixes a spelling, the other takes a project off every board in the
 * organization. A single "Edit" that could do both is how somebody fixes a typo
 * and hides a division in the same click.
 */
final class ProjectService
{
    use RecordsDomainEvents;

    /** What a PATCH may change. Anything else is not an edit (see update()). */
    private const EDITABLE = [
        'name', 'description', 'department_id', 'visibility',
        'priority', 'status', 'start_date', 'end_date',
    ];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Correct a project.
     *
     * The key is NOT editable and is not silently dropped — the controller
     * refuses it by name. `ENG` is in every work item reference the project has
     * ever produced (docs/08 §2), so renaming it would orphan every one of
     * them; that is a migration, not an edit.
     *
     * Only what actually changed is written, and only that is logged: a save
     * that reports every field as touched turns the history into noise nobody
     * reads.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(ProjectModel $project, array $changes, ?int $lockVersion = null): ProjectModel
    {
        return $this->transactional(function () use ($project, $changes, $lockVersion): ProjectModel {
            $this->assertNotStale($project, $lockVersion);

            /** @var ProjectModel $locked */
            $locked = ProjectModel::query()->lockForUpdate()->findOrFail($project->getKey());

            $diff = [];

            foreach ($changes as $field => $value) {
                if (! in_array($field, self::EDITABLE, strict: true)) {
                    continue;
                }

                $before = $locked->getAttribute($field);

                if ($before instanceof \DateTimeInterface) {
                    $before = $before->format('Y-m-d');
                }

                if ((string) $before !== (string) $value) {
                    $diff[$field] = ['from' => $before, 'to' => $value];
                }
            }

            if ($diff === []) {
                return $locked;
            }

            $locked->forceFill(
                array_intersect_key($changes, array_flip(self::EDITABLE))
                + ['lock_version' => $locked->lock_version + 1]
            )->save();

            // One user action, one correlation id, so the timeline can collapse
            // "changed 3 fields" into a single entry (docs/03 §6).
            $this->activity->grouped(function () use ($locked, $diff): void {
                $this->activity->record('project', (string) $locked->getKey(), 'updated', $diff);
            });

            return $locked;
        });
    }

    /**
     * Take a project off the boards, or bring it back.
     *
     * `archived_at`, not a status: `status` says how the work is going —
     * planning, active, on hold — and "archived" is not one of those answers.
     * Conflating them would make "on hold" and "put away" the same state, and
     * a project resumed from hold would come back as whatever it was archived
     * as.
     *
     * Nothing is deleted, and this is deliberately reversible. A project
     * carries work items, time entries and history; the honest way to make it
     * stop appearing is to stop listing it.
     */
    public function setArchived(ProjectModel $project, bool $archived): ProjectModel
    {
        return $this->transactional(function () use ($project, $archived): ProjectModel {
            /** @var ProjectModel $locked */
            $locked = ProjectModel::query()->lockForUpdate()->findOrFail($project->getKey());

            if (($locked->archived_at !== null) === $archived) {
                return $locked;
            }

            $locked->forceFill([
                'archived_at' => $archived ? now() : null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $this->activity->record(
                'project',
                (string) $locked->getKey(),
                $archived ? 'archived' : 'restored',
                [],
            );

            return $locked;
        });
    }

    /**
     * Who can see and work on this project (ADR 0041).
     *
     * Current rows only. A removed member is history and the table keeps it —
     * `removed_at`, never a DELETE — but "who is on this project" is a question
     * about now, and a list that mixes the two answers neither.
     *
     * @return Collection<int, ProjectMemberModel>
     */
    public function members(ProjectModel $project): Collection
    {
        return ProjectMemberModel::query()
            ->where('project_id', $project->getKey())
            ->whereNull('removed_at')
            ->with(['membership.user:id,name,avatar_path', 'team:id,name,key'])
            // Owners first, then managers, then everybody by name. A member
            // list whose order is insertion order makes "who runs this" a
            // question you answer by reading every row.
            ->orderByRaw("array_position(ARRAY['owner','manager','member','viewer'], role)")
            ->orderBy('added_at')
            ->get();
    }

    /**
     * Give a person or a team access to this project.
     *
     * Exactly one subject, which the database also enforces with a CHECK. Both
     * say it because they say it to different audiences: the constraint makes
     * it true of every row whatever writes it, and the refusal here is a
     * sentence the person can act on.
     *
     * **Team access is not a copy of a team roster.** A row naming a team
     * follows that team as people join and leave it, which is the whole reason
     * the column exists — a project's member list assembled by hand from a team
     * is a list that goes stale the first time somebody moves.
     */
    public function addMember(
        ProjectModel $project,
        ?string $membershipId,
        ?string $teamId,
        string $role = 'member',
    ): ProjectMemberModel {
        if (($membershipId === null) === ($teamId === null)) {
            throw ProjectMembershipRefused::needsExactlyOneSubject();
        }

        return $this->transactional(function () use ($project, $membershipId, $teamId, $role): ProjectMemberModel {
            $clash = ProjectMemberModel::query()
                ->where('project_id', $project->getKey())
                ->whereNull('removed_at')
                ->when($membershipId !== null, fn ($q) => $q->where('membership_id', $membershipId))
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->exists();

            if ($clash) {
                throw $membershipId !== null
                    ? ProjectMembershipRefused::alreadyAMember()
                    : ProjectMembershipRefused::teamAlreadyAdded();
            }

            $member = new ProjectMemberModel;
            $member->forceFill([
                'id' => ProjectMemberModel::newId(),
                'project_id' => $project->getKey(),
                'membership_id' => $membershipId,
                'team_id' => $teamId,
                'role' => $role,
                'added_by' => $this->tenant->membershipId(),
                'added_at' => now(),
            ])->save();

            $this->activity->record('project', (string) $project->getKey(), 'member_added', [
                'role' => ['from' => null, 'to' => $role],
            ]);

            return $member;
        });
    }

    /**
     * Take access away, keeping the record that it existed.
     *
     * `removed_at`, not a DELETE: who had access to a project and when is
     * exactly the question an audit asks later, and a deleted row answers it
     * with silence.
     */
    public function removeMember(ProjectModel $project, string $memberId): void
    {
        $this->transactional(function () use ($project, $memberId): void {
            $member = ProjectMemberModel::query()
                ->where('project_id', $project->getKey())
                ->whereNull('removed_at')
                ->find($memberId);

            if (! $member instanceof ProjectMemberModel) {
                throw ProjectMembershipRefused::notAMember();
            }

            if ($member->role === 'owner' && $this->ownerCount($project) <= 1) {
                throw ProjectMembershipRefused::lastOwner();
            }

            $member->forceFill(['removed_at' => now()])->save();

            $this->activity->record('project', (string) $project->getKey(), 'member_removed', [
                'role' => ['from' => $member->role, 'to' => null],
            ]);
        });
    }

    /**
     * Change what somebody may do on this project.
     *
     * Demoting the last owner is refused for the same reason removing them is:
     * the owner row is what lets `ProjectPolicy` say yes to somebody who does
     * not hold the organization-wide permission, so a project with none is a
     * project only an administrator can fix.
     */
    public function setMemberRole(ProjectModel $project, string $memberId, string $role): ProjectMemberModel
    {
        return $this->transactional(function () use ($project, $memberId, $role): ProjectMemberModel {
            $member = ProjectMemberModel::query()
                ->where('project_id', $project->getKey())
                ->whereNull('removed_at')
                ->find($memberId);

            if (! $member instanceof ProjectMemberModel) {
                throw ProjectMembershipRefused::notAMember();
            }

            if ($member->role === $role) {
                return $member;
            }

            if ($member->role === 'owner' && $role !== 'owner' && $this->ownerCount($project) <= 1) {
                throw ProjectMembershipRefused::lastOwner();
            }

            $before = $member->role;
            $member->forceFill(['role' => $role])->save();

            $this->activity->record('project', (string) $project->getKey(), 'member_role_changed', [
                'role' => ['from' => $before, 'to' => $role],
            ]);

            return $member;
        });
    }

    /**
     * Create a project, and put its creator on it (ADR 0046).
     *
     * One transaction, because the second write is not optional: `project_members`
     * is what decides project visibility, so a project created without its
     * creator is a project the creator cannot then see. That is the kind of bug
     * that only shows up in production, and a partial commit would produce it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ProjectModel
    {
        return $this->transactional(function () use ($attributes): ProjectModel {
            $project = new ProjectModel;
            $id = ProjectModel::newId();

            $project->forceFill($attributes + [
                'id' => $id,
                'owner_membership_id' => $this->tenant->membershipId(),
                // The organization's default task workflow. Asked of the table
                // rather than named by a constant: which workflow is default is
                // a setting, and a constant here would be a second copy of it.
                'workflow_id' => DB::table('workflows')
                    ->where('organization_id', $this->tenant->organizationId())
                    ->where('applies_to_type', 'task')
                    ->where('is_default', true)
                    ->value('id'),
            ])->save();

            DB::table('project_members')->insert([
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'project_id' => $id,
                'membership_id' => $this->tenant->membershipId(),
                'role' => 'owner',
                'added_at' => now(),
            ]);

            $this->activity->record('project', (string) $id, 'created', [
                'key' => ['from' => null, 'to' => $project->key],
                'name' => ['from' => null, 'to' => $project->name],
            ]);

            return $project;
        });
    }

    /**
     * Pin a project for one person, or unpin it.
     *
     * Idempotent in both directions, and the database is what makes it so: the
     * unique index refuses a second pin, so this does not depend on a caller
     * checking first. Pinning twice is not an error the person should be shown
     * — they wanted it pinned, and it is.
     *
     * `insertOrIgnore`, not `firstOrCreate`. Two reasons, and the first is
     * architectural: `firstOrCreate` MASS ASSIGNS, and this codebase forbids
     * that outright — every write names its columns, so no model here has a
     * `$fillable` and the attempt threw. The second is the better reason:
     * `ON CONFLICT DO NOTHING` is what makes pinning idempotent AT THE DATABASE,
     * in one statement, rather than in a read-then-write that two clicks in the
     * same second both pass.
     *
     * Nothing is recorded in the activity log, deliberately. A pin is one
     * person's arrangement of their own sidebar, not something that happened to
     * the project, and putting it in the project's history would bury the acts
     * that did.
     */
    public function setPinned(ProjectModel $project, string $membershipId, bool $pinned): void
    {
        if (! $pinned) {
            PinnedProjectModel::query()
                ->where('membership_id', $membershipId)
                ->where('project_id', $project->getKey())
                ->delete();

            return;
        }

        DB::table('pinned_projects')->insertOrIgnore([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->organizationId(),
            'membership_id' => $membershipId,
            'project_id' => $project->getKey(),
            // Appended, not inserted at the top: the list is the person's own
            // order, and a new pin has not earned a place in it.
            'position' => (int) DB::table('pinned_projects')
                ->where('membership_id', $membershipId)
                ->max('position') + 1,
            'created_at' => now(),
        ]);
    }

    private function ownerCount(ProjectModel $project): int
    {
        return ProjectMemberModel::query()
            ->where('project_id', $project->getKey())
            ->whereNull('removed_at')
            ->where('role', 'owner')
            ->count();
    }

    /**
     * Optimistic locking (docs/03 §8 rule 3).
     *
     * `null` means the caller sent no version, which disables the check — the
     * same contract `WorkItemService` uses, and the same trap: version 0 is a
     * real version, so a caller must distinguish "absent" from "zero" or the
     * first edit of every project silently races.
     */
    private function assertNotStale(ProjectModel $project, ?int $expected): void
    {
        if ($expected === null) {
            return;
        }

        $current = (int) DB::table('projects')->where('id', $project->getKey())->value('lock_version');

        if ($current !== $expected) {
            throw new ConcurrencyConflict('This project changed while you were editing it.', [
                'your_version' => $expected,
                'current_version' => $current,
            ]);
        }
    }
}
