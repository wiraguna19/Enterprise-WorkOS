<?php

declare(strict_types=1);

namespace App\Modules\Search\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The searches a person made and then acted on, newest first.
 *
 * Written when a result is OPENED, not on every keystroke: the palette searches
 * as somebody types, and remembering "sa", "sar", "sara" would fill the list
 * with fragments. A search that led somewhere is the one worth offering back.
 */
final class RecentSearches
{
    /** How many are kept per person. */
    public const KEEP = 10;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** @return list<array{id: string, query: string, type: string|null, searched_at: string}> */
    public function mine(): array
    {
        return array_values(DB::table('recent_searches')
            ->where('membership_id', $this->tenant->membershipId())
            ->orderByDesc('searched_at')
            ->limit(self::KEEP)
            ->get(['id', 'query', 'type', 'searched_at'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'query' => (string) $row->query,
                'type' => $row->type === null ? null : (string) $row->type,
                'searched_at' => (string) $row->searched_at,
            ])
            ->all());
    }

    /** Remember a search, or move it to the top if it is already remembered. */
    public function remember(string $query, ?string $type): void
    {
        $membershipId = $this->tenant->membershipId();
        $query = trim($query);

        DB::transaction(function () use ($membershipId, $query, $type): void {
            DB::statement(
                <<<'SQL'
                INSERT INTO recent_searches (id, organization_id, membership_id, query, type, searched_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (membership_id, lower(query), COALESCE(type, ''))
                DO UPDATE SET searched_at = EXCLUDED.searched_at, query = EXCLUDED.query
                SQL,
                // The application's clock, not the database's `now()`: inside a
                // transaction Postgres returns the transaction's start, so two
                // searches in one request — or one test — would tie.
                [(string) new UuidV7, $this->tenant->organizationId(), $membershipId, $query, $type, now()],
            );

            // Only the newest few are kept; the rest are gone, not hidden.
            $keep = DB::table('recent_searches')
                ->where('membership_id', $membershipId)
                ->orderByDesc('searched_at')
                ->limit(self::KEEP)
                ->pluck('id');

            DB::table('recent_searches')
                ->where('membership_id', $membershipId)
                ->whereNotIn('id', $keep)
                ->delete();
        });
    }

    /** Forget one of this person's searches. Someone else's id matches nothing. */
    public function forget(string $id): void
    {
        DB::table('recent_searches')
            ->where('membership_id', $this->tenant->membershipId())
            ->where('id', $id)
            ->delete();
    }

    /** Forget all of this person's searches. */
    public function forgetAll(): void
    {
        DB::table('recent_searches')
            ->where('membership_id', $this->tenant->membershipId())
            ->delete();
    }
}
