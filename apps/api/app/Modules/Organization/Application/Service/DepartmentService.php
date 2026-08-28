<?php

declare(strict_types=1);

namespace App\Modules\Organization\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Organization\Domain\Exception\DepartmentCycle;
use App\Modules\Organization\Domain\Exception\DepartmentTooDeep;
use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;
use App\Modules\Platform\Application\Event\RecordsDomainEvents;
use Illuminate\Support\Facades\DB;

/**
 * Owns the materialized path invariant.
 *
 * The path is derived state, and derived state is exactly what rots when it can
 * be written from more than one place — so it is written here and nowhere else.
 * Every mutation happens inside a transaction that also rewrites descendants,
 * because a half-moved subtree is a corrupted hierarchy.
 *
 * See docs/03 §2.
 */
final class DepartmentService
{
    use RecordsDomainEvents;

    private const MAX_DEPTH = 6;

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    public function create(string $name, string $code, ?string $parentId = null): DepartmentModel
    {
        return $this->transactional(function () use ($name, $code, $parentId): DepartmentModel {
            $parent = $parentId !== null
                ? DepartmentModel::query()->lockForUpdate()->findOrFail($parentId)
                : null;

            $depth = $parent !== null ? $parent->depth + 1 : 0;

            if ($depth > self::MAX_DEPTH) {
                throw new DepartmentTooDeep(
                    'Department nesting is limited to '.self::MAX_DEPTH.' levels.',
                    ['attempted_depth' => $depth],
                );
            }

            $department = new DepartmentModel;
            $id = DepartmentModel::newId();

            $department->forceFill([
                'id' => $id,
                'name' => $name,
                'code' => mb_strtoupper($code),
                'parent_id' => $parent?->getKey(),
                'depth' => $depth,
                'path' => ($parent === null ? '/' : $parent->path).$id.'/',
            ])->save();

            $this->activity->record('department', $id, 'created', [
                'name' => ['from' => null, 'to' => $name],
            ]);

            return $department;
        });
    }

    /**
     * Move a subtree.
     *
     * Two invariants are checked before anything is written: a department may
     * not become its own ancestor (which would produce an unreachable island of
     * rows), and the deepest descendant must still fit inside the depth limit
     * after the move.
     */
    public function move(DepartmentModel $department, ?string $newParentId): DepartmentModel
    {
        return $this->transactional(function () use ($department, $newParentId): DepartmentModel {
            $newParent = $newParentId !== null
                ? DepartmentModel::query()->lockForUpdate()->findOrFail($newParentId)
                : null;

            if ($newParent !== null && str_starts_with($newParent->path, $department->path)) {
                throw new DepartmentCycle(
                    'A department cannot be moved beneath one of its own descendants.',
                    ['department_id' => $department->id, 'target_parent_id' => $newParentId],
                );
            }

            $oldPath = $department->path;
            $newDepth = $newParent !== null ? $newParent->depth + 1 : 0;
            $newPath = ($newParent === null ? '/' : $newParent->path).$department->id.'/';
            $shift = $newDepth - $department->depth;

            $deepest = (int) DepartmentModel::query()
                ->where('path', 'like', $oldPath.'%')
                ->max('depth');

            if ($deepest + $shift > self::MAX_DEPTH) {
                throw new DepartmentTooDeep(
                    'This move would push descendants past the '.self::MAX_DEPTH.'-level limit.',
                    ['resulting_depth' => $deepest + $shift],
                );
            }

            // Rewrite the whole subtree in one statement: N updates in a loop
            // would be both slow and non-atomic in effect.
            DB::update(
                // The ?::int cast is load-bearing: PDO sends the offset as an
                // untyped parameter, and PostgreSQL then resolves
                // substring(text FROM text) — the REGEX overload — which returns
                // NULL for a path that does not look like a pattern. The column
                // is NOT NULL, so the whole move fails on a cast nobody sees.
                'UPDATE departments
                    SET path  = ? || substring(path from ?::int),
                        depth = depth + ?,
                        updated_at = now()
                  WHERE organization_id = ?
                    AND path LIKE ?',
                [$newPath, mb_strlen($oldPath) + 1, $shift, $department->organization_id, $oldPath.'%'],
            );

            $department->forceFill([
                'parent_id' => $newParent?->getKey(),
                'path' => $newPath,
                'depth' => $newDepth,
            ])->save();

            $this->activity->record('department', (string) $department->id, 'moved', [
                'parent_id' => ['from' => $department->getOriginal('parent_id'), 'to' => $newParentId],
            ]);

            return $department->refresh();
        });
    }
}
