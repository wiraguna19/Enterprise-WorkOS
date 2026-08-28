<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Query;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor pagination is the default across the API.
 *
 * Offset pagination on a list sorted by a column that changes under you
 * (due_at, position, updated_at) silently duplicates and skips rows while the
 * user pages — and deep offsets degrade into sequential scans. Cursors are
 * stable and constant-time (docs/05-api-architecture.md §3, docs/12 §6).
 *
 * @template TModel of Model
 */
final class CursorPage
{
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 50;

    /** @param CursorPaginator<int, TModel> $paginator */
    public function __construct(
        public readonly CursorPaginator $paginator,
    ) {}

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return [
            'pagination' => [
                'per_page' => $this->paginator->perPage(),
                'next_cursor' => $this->paginator->nextCursor()?->encode(),
                'prev_cursor' => $this->paginator->previousCursor()?->encode(),
                'has_more' => $this->paginator->hasMorePages(),
            ],
        ];
    }

    public static function perPage(?int $requested): int
    {
        if ($requested === null || $requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
