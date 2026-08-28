<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Domain\Exception\DependencyCycle;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemDependencyModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Dependencies form a DAG, and PostgreSQL cannot declare "acyclic".
 *
 * The schema catches the trivial self-edge with a CHECK; everything longer is
 * this class's responsibility, verified by a recursive CTE before every insert.
 * The constraint proof suite documents that gap explicitly so nobody later
 * assumes the database is handling it.
 */
final class DependencyService
{
    private const MAX_TRAVERSAL_DEPTH = 20;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TenantContext $tenant,
    ) {}

    public function link(
        WorkItemModel $item,
        string $dependsOnId,
        string $type = 'blocks',
    ): WorkItemDependencyModel {
        return DB::transaction(function () use ($item, $dependsOnId, $type): WorkItemDependencyModel {
            $target = WorkItemModel::query()->findOrFail($dependsOnId);

            if ($type === 'blocks' && $this->wouldCreateCycle((string) $item->getKey(), $dependsOnId)) {
                throw new DependencyCycle(
                    'That would create a circular dependency.',
                    [
                        'work_item' => $item->reference,
                        'depends_on' => $target->reference,
                        'path' => $this->pathBetween($dependsOnId, (string) $item->getKey()),
                    ],
                );
            }

            $dependency = new WorkItemDependencyModel;
            $dependency->forceFill([
                'id' => WorkItemDependencyModel::newId(),
                'work_item_id' => $item->getKey(),
                'depends_on_work_item_id' => $dependsOnId,
                'type' => $type,
                // Null for a rule-created dependency: a job has an
                // organization and no membership (docs/01 §6).
                'created_by' => $this->tenant->hasMembership()
                    ? $this->tenant->membershipId()
                    : null,
            ])->save();

            $this->activity->record('work_item', (string) $item->getKey(), 'dependency_added', [
                'depends_on' => ['from' => null, 'to' => $target->reference],
            ]);

            return $dependency;
        });
    }

    public function unlink(WorkItemModel $item, string $dependencyId): void
    {
        DB::transaction(function () use ($item, $dependencyId): void {
            WorkItemDependencyModel::query()
                ->where('work_item_id', $item->getKey())
                ->findOrFail($dependencyId)
                ->delete();

            $this->activity->record('work_item', (string) $item->getKey(), 'dependency_removed');
        });
    }

    /**
     * The blockers that are still open.
     *
     * Closing work with open blockers is refused unless overridden with a
     * recorded reason (docs/02 §4.2) — so this returns references, not just a
     * boolean, because the error message has to name what is in the way.
     *
     * @return list<string>
     */
    public function openBlockersFor(WorkItemModel $item): array
    {
        return array_values(
            DB::table('work_item_dependencies as d')
                ->join('work_items as w', 'w.id', '=', 'd.depends_on_work_item_id')
                ->where('d.work_item_id', $item->getKey())
                ->where('d.type', 'blocks')
                ->whereNotIn('w.state_category', ['done', 'cancelled'])
                ->whereNull('w.deleted_at')
                ->pluck('w.reference')
                ->map(static fn ($reference): string => (string) $reference)
                ->all()
        );
    }

    /**
     * Would adding item → dependsOn close a loop?
     *
     * Equivalent question: is `item` already reachable from `dependsOn`? Depth
     * is capped so a pre-existing cycle in the data cannot spin this forever.
     */
    private function wouldCreateCycle(string $itemId, string $dependsOnId): bool
    {
        if ($itemId === $dependsOnId) {
            return true;
        }

        $reachable = DB::select(<<<'SQL'
            WITH RECURSIVE reachable(id, depth) AS (
                SELECT depends_on_work_item_id, 1
                  FROM work_item_dependencies
                 WHERE work_item_id = ? AND type = 'blocks' AND organization_id = ?
                UNION ALL
                SELECT d.depends_on_work_item_id, r.depth + 1
                  FROM work_item_dependencies d
                  JOIN reachable r ON d.work_item_id = r.id
                 WHERE d.type = 'blocks' AND d.organization_id = ? AND r.depth < ?
            )
            SELECT 1 FROM reachable WHERE id = ? LIMIT 1
        SQL, [
            $dependsOnId,
            $this->tenant->organizationId(),
            $this->tenant->organizationId(),
            self::MAX_TRAVERSAL_DEPTH,
            $itemId,
        ]);

        return $reachable !== [];
    }

    /**
     * The chain that would close the loop, so the error can show it rather than
     * just refusing.
     *
     * @return list<string>
     */
    private function pathBetween(string $fromId, string $toId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE path(id, chain, depth) AS (
                SELECT depends_on_work_item_id, ARRAY[work_item_id, depends_on_work_item_id], 1
                  FROM work_item_dependencies
                 WHERE work_item_id = ? AND type = 'blocks' AND organization_id = ?
                UNION ALL
                SELECT d.depends_on_work_item_id, p.chain || d.depends_on_work_item_id, p.depth + 1
                  FROM work_item_dependencies d
                  JOIN path p ON d.work_item_id = p.id
                 WHERE d.type = 'blocks' AND d.organization_id = ?
                   AND p.depth < ? AND NOT d.depends_on_work_item_id = ANY(p.chain)
            )
            SELECT chain FROM path WHERE id = ? ORDER BY depth LIMIT 1
        SQL, [
            $fromId,
            $this->tenant->organizationId(),
            $this->tenant->organizationId(),
            self::MAX_TRAVERSAL_DEPTH,
            $toId,
        ]);

        if ($rows === []) {
            return [];
        }

        $ids = str_getcsv(trim((string) $rows[0]->chain, '{}'));

        return array_values(
            WorkItemModel::query()
                ->whereIn('id', $ids)
                ->pluck('reference')
                ->map(static fn ($reference): string => (string) $reference)
                ->all()
        );
    }
}
