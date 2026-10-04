<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controller;

use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Search\Application\Service\RecentSearches;
use App\Modules\Search\Http\Request\SearchRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The palette's memory: what this person searched for and opened.
 *
 * Only ever the caller's own. There is no way to read somebody else's, by
 * design rather than by permission — what a colleague looks for is not a
 * fact about the work.
 */
final class RecentSearchController extends ApiController
{
    public function __construct(
        private readonly RecentSearches $recent,
    ) {}

    public function index(): ApiResponse
    {
        return ApiResponse::collection($this->recent->mine());
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(SearchRequest::TYPES)],
        ]);

        $this->recent->remember((string) $validated['query'], $validated['type'] ?? null);

        return response()->noContent();
    }

    public function destroy(string $id): Response
    {
        $this->recent->forget($id);

        return response()->noContent();
    }

    public function destroyAll(): Response
    {
        $this->recent->forgetAll();

        return response()->noContent();
    }
}
