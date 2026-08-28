<?php

declare(strict_types=1);

namespace App\Modules\Search\Domain\Contract;

/**
 * The seam that keeps PostgreSQL from becoming a decision we cannot revisit.
 *
 * docs/12 §9 buys Postgres full-text search — no extra infrastructure, and an
 * index that is transactionally consistent with the data — and pays for it in
 * relevance, fuzzy matching, and behaviour somewhere in the low millions of
 * rows. The mitigation stated there is this interface: when p95 crosses 500 ms
 * or ranking complaints stop being fixable, a second implementation is one
 * class, not a project.
 *
 * So nothing above this interface may know what a tsvector is.
 */
interface SearchDriver
{
    /**
     * @param  list<string>  $types  subset of `work_item`, `project`, `person`
     * @return list<array{
     *     type: string,
     *     id: string,
     *     title: string,
     *     subtitle: string|null,
     *     reference: string|null,
     *     matched_on: string,
     *     rank: float
     * }>
     */
    public function search(string $terms, array $types, int $limit): array;
}
