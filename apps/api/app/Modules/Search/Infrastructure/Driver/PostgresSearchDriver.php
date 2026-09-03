<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure\Driver;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Search\Domain\Contract\SearchDriver;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * Search as three visibility-scoped queries, not one UNION over the tenant.
 *
 * The tempting shape is a single UNION ALL across every searchable table with
 * `organization_id = ?` on each arm. It is also how a search box becomes the
 * one place a private project leaks: tenant scoping answers "whose data is
 * this", and visibility answers "may THIS person see it" — two different
 * questions, and only the first is a column (docs/06 §2).
 *
 * So each source runs through the same visibility rule its own list endpoint
 * uses. Search is a way to reach records, never a way to discover ones you
 * could not otherwise open.
 */
final class PostgresSearchDriver implements SearchDriver
{
    /**
     * Ranking is comparable across sources only because every arm uses the same
     * weights and the same ts_rank. Comment matches are damped: finding the
     * item is the point, and a passing remark should not outrank a work item
     * whose title IS the phrase.
     */
    private const COMMENT_RANK_FACTOR = 0.4;

    /**
     * How many matching ids each arm may contribute before ranking.
     *
     * Ten times a palette page: large enough that visibility filtering still
     * leaves a full page for any ordinary reader, small enough that a search
     * for a common word does not rank fifty thousand rows on a keystroke.
     */
    private const CANDIDATE_POOL = 200;

    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
        private readonly TenantContext $tenant,
    ) {}

    /** {@inheritDoc} */
    public function search(string $terms, array $types, int $limit): array
    {
        $results = [];

        if (in_array('work_item', $types, strict: true)) {
            $results = [...$results, ...$this->workItems($terms, $limit)];
        }

        if (in_array('project', $types, strict: true)) {
            $results = [...$results, ...$this->projects($terms, $limit)];
        }

        if (in_array('person', $types, strict: true)) {
            $results = [...$results, ...$this->people($terms, $limit)];
        }

        usort($results, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        /** @var list<array{type: string, id: string, title: string, subtitle: string|null, reference: string|null, matched_on: string, rank: float}> $ranked */
        $ranked = array_slice($results, 0, $limit);

        return $ranked;
    }

    /**
     * Title, description, reference and comment text, ranked together.
     *
     * The matching happens in `candidateIds()` and the ranking here, because
     * the two want opposite query shapes — see that method for the measurement
     * that forced the split.
     *
     * @return list<array<string, mixed>>
     */
    private function workItems(string $terms, int $limit): array
    {
        $candidates = $this->candidateIds($terms);

        if ($candidates === []) {
            return [];
        }

        $query = WorkItemModel::query()
            ->with(['project:id,key,name'])
            ->whereNull('deleted_at')
            ->whereIn('work_items.id', $candidates);

        // Visibility is applied to the candidates rather than replaced by them:
        // the shortlist is about which rows MATCH, and this is still the only
        // thing deciding which rows this person may see.
        $this->visibility->apply($query);

        $query->selectRaw(
            "work_items.*,
             -- Why this row matched, decided by the database along with the
             -- ranking. Computing it per result in PHP meant one extra round
             -- trip per row — the exact N+1 the palette's query budget exists
             -- to catch, since it fires on every keystroke (docs/11 §3).
             case
                 when upper(work_items.reference) = upper(?) then 'reference'
                 when to_tsvector('english', coalesce(work_items.title, ''))
                      @@ websearch_to_tsquery('english', ?) then 'title'
                 when work_items.search_vector @@ websearch_to_tsquery('english', ?) then 'description'
                 else 'comment'
             end as matched_on,
             greatest(
                 ts_rank(work_items.search_vector, websearch_to_tsquery('english', ?)),
                 case when upper(work_items.reference) = upper(?) then 1.0 else 0 end,
                 coalesce((
                     select max(ts_rank(c.search_vector, websearch_to_tsquery('english', ?))) * ?
                       from comments c
                      where c.commentable_id = work_items.id
                        and c.commentable_type = 'work_item'
                        and c.deleted_at is null
                 ), 0)
             ) as search_rank",
            [$terms, $terms, $terms, $terms, $terms, $terms, self::COMMENT_RANK_FACTOR],
        );

        return array_values($query->orderByDesc('search_rank')
            ->limit($limit)
            ->get()
            ->map(fn (WorkItemModel $item): array => [
                'type' => 'work_item',
                'id' => (string) $item->getKey(),
                'title' => (string) $item->title,
                'subtitle' => $item->project?->name,
                'reference' => (string) $item->reference,
                'matched_on' => (string) $item->getAttribute('matched_on'),
                'rank' => (float) $item->getAttribute('search_rank'),
            ])
            ->all());
    }

    /**
     * The ids that match, found by three index-driven lookups rather than one
     * OR.
     *
     * This used to be a single `WHERE a OR b OR c` on `work_items`, which reads
     * better and cannot use an index. Postgres builds a bitmap OR only when
     * EVERY arm is indexable: the reference arm calls `upper()` on the column,
     * and the comment arm is a correlated EXISTS, so the planner fell back to
     * scanning `work_items` whole. Measured at 50 000 items, that was ~300 ms —
     * and identical for a common word and for a single reference, which is the
     * signature of a full scan and the reason this was found at all
     * (`perf:seed-volume`, `perf:measure`).
     *
     * Each arm now runs against the index built for it: the GIN on
     * `search_vector`, the functional index on `upper(reference)`, and the
     * comments' own GIN — driven FROM comments rather than testing each work
     * item, so the third arm reads the small matching set instead of the large
     * candidate one.
     *
     * The pool is capped. A reader whose visibility excludes most of a very
     * large match set could therefore see fewer than `limit` results where the
     * old shape would have kept looking — the trade this makes for not scanning
     * the table on every keystroke. The cap is ten times a palette page, so it
     * takes an unusual combination to reach.
     *
     * @return list<string>
     */
    private function candidateIds(string $terms): array
    {
        $organizationId = $this->tenant->organizationId();

        /** @var list<object{id: string}> $rows */
        $rows = DB::select(<<<'SQL'
            (SELECT id
               FROM work_items
              WHERE organization_id = ?
                AND deleted_at IS NULL
                AND search_vector @@ websearch_to_tsquery('english', ?)
              ORDER BY ts_rank(search_vector, websearch_to_tsquery('english', ?)) DESC
              LIMIT ?)
            UNION
            -- The reference is what people paste from chat, and it is not in
            -- the tsvector: "ENG-142" tokenises into pieces that match every
            -- other ENG item.
            (SELECT id
               FROM work_items
              WHERE organization_id = ?
                AND deleted_at IS NULL
                AND upper(reference) = upper(?)
              LIMIT ?)
            UNION
            -- Driven from the comments, so an item discussed twenty times still
            -- arrives once and the scan is over the matches rather than over
            -- every work item.
            (SELECT DISTINCT c.commentable_id AS id
               FROM comments c
              WHERE c.organization_id = ?
                AND c.commentable_type = 'work_item'
                AND c.deleted_at IS NULL
                AND c.search_vector @@ websearch_to_tsquery('english', ?)
              LIMIT ?)
        SQL, [
            $organizationId, $terms, $terms, self::CANDIDATE_POOL,
            $organizationId, $terms, self::CANDIDATE_POOL,
            $organizationId, $terms, self::CANDIDATE_POOL,
        ]);

        return array_values(array_map(static fn (object $row): string => (string) $row->id, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function projects(string $terms, int $limit): array
    {
        $actor = $this->acting->getOrFail();

        return array_values(ProjectModel::query()
            ->visibleTo(
                $this->tenant->membershipId(),
                $this->permissions->has($actor, 'project.view_all'),
            )
            ->whereNull('deleted_at')
            ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$terms])
            ->selectRaw(
                "projects.*, ts_rank(search_vector, websearch_to_tsquery('english', ?)) as search_rank",
                [$terms],
            )
            ->orderByDesc('search_rank')
            ->limit($limit)
            ->get()
            ->map(fn (ProjectModel $project): array => [
                'type' => 'project',
                'id' => (string) $project->getKey(),
                'title' => (string) $project->name,
                'subtitle' => (string) $project->key,
                'reference' => (string) $project->key,
                'matched_on' => 'project',
                'rank' => (float) $project->getAttribute('search_rank'),
            ])
            ->all());
    }

    /**
     * People are matched by prefix, not by tsvector.
     *
     * Names are not English prose: stemming turns "Chen" and "Chens" into the
     * same token and does nothing useful for "Sar", which is what someone types
     * three keystrokes into the palette. A prefix match on name and email is
     * both more predictable and what the interaction actually needs.
     *
     * @return list<array<string, mixed>>
     */
    private function people(string $terms, int $limit): array
    {
        $like = mb_strtolower($terms).'%';
        $contains = '%'.mb_strtolower($terms).'%';

        $rows = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->leftJoin('employee_profiles', function ($join): void {
                $join->on('employee_profiles.membership_id', '=', 'memberships.id')
                    ->whereColumn('employee_profiles.organization_id', 'memberships.organization_id');
            })
            ->where('memberships.organization_id', $this->tenant->organizationId())
            ->where('memberships.status', 'active')
            ->where(function ($q) use ($like, $contains): void {
                $q->whereRaw('lower(users.name) like ?', [$like])
                    ->orWhereRaw('lower(users.email) like ?', [$like])
                    ->orWhereRaw('lower(users.name) like ?', [$contains]);
            })
            ->orderByRaw('case when lower(users.name) like ? then 0 else 1 end, users.name', [$like])
            ->limit($limit)
            ->get(['memberships.id', 'users.name', 'users.email', 'employee_profiles.job_title']);

        return array_values($rows->map(static fn (object $row): array => [
            'type' => 'person',
            'id' => (string) $row->id,
            'title' => (string) $row->name,
            'subtitle' => $row->job_title === null ? (string) $row->email : (string) $row->job_title,
            'reference' => null,
            'matched_on' => 'person',
            // Below every real text match: a person is a navigation target, not
            // an answer to "where was this discussed".
            'rank' => 0.05,
        ])->all());
    }
}
