<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controller;

use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Search\Domain\Contract\SearchDriver;
use App\Modules\Search\Http\Request\SearchRequest;

/**
 * The command palette's backend (docs/08 §5).
 *
 * One endpoint across work items, projects, and people, because the palette is
 * one box: making the client ask three endpoints and merge them would put the
 * ranking decision in the browser, where it cannot see what it is ranking.
 */
final class SearchController extends ApiController
{
    public function __construct(
        private readonly SearchDriver $search,
    ) {}

    public function __invoke(SearchRequest $request): ApiResponse
    {
        $results = $this->search->search(
            $request->terms(),
            $request->types(),
            $request->limit(),
        );

        return ApiResponse::collection($results, [
            'query' => $request->terms(),
            'types' => $request->types(),
            'count' => count($results),
        ]);
    }
}
