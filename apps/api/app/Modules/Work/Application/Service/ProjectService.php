<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use App\Modules\Platform\Domain\Exception\ConcurrencyConflict;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use Illuminate\Support\Facades\DB;

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
