<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Platform\Application\Query\CursorPage;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\MyWorkQuery;
use App\Modules\Work\Http\Resource\WorkItemResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "My Work" is domain vocabulary, so it earns an endpoint (docs/05 §2).
 *
 * What it must not become is a mirror of whatever the home screen renders this
 * month — the views here are the ones in the ubiquitous language, and adding
 * one is a domain decision, not a UI convenience.
 */
final class MyWorkController extends ApiController
{
    public function __construct(
        private readonly MyWorkQuery $myWork,
    ) {}

    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'view' => ['sometimes', Rule::in(MyWorkQuery::VIEWS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $view = $validated['view'] ?? 'today';

        $page = new CursorPage(
            $this->myWork->forView($view)
                ->cursorPaginate(CursorPage::perPage($request->integer('limit')))
        );

        return ApiResponse::collection(
            WorkItemResource::collection($page->paginator->items()),
            $page->meta() + ['view' => $view],
        );
    }

    /** Badge counts for the shell, in one grouped query rather than eight. */
    public function counts(): ApiResponse
    {
        return $this->ok($this->myWork->counts());
    }

    /**
     * Work assigned to this person that they have not acknowledged.
     *
     * Rendered as the first section of the employee home screen, above
     * everything due today: exceptions first (docs/08 §3).
     */
    public function needsAttention(): ApiResponse
    {
        return $this->ok([
            'unaccepted' => WorkItemResource::collection(
                $this->myWork->unacceptedAssignments()->limit(10)->get()
            ),
            'overdue' => WorkItemResource::collection(
                $this->myWork->forView('overdue')->limit(10)->get()
            ),
        ]);
    }
}
