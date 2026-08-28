<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controller;

use App\Modules\Collaboration\Application\Service\CommentService;
use App\Modules\Collaboration\Http\Resource\CommentResource;
use App\Modules\Collaboration\Infrastructure\Eloquent\CommentModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Http\Request;

final class CommentController extends ApiController
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly WorkItemVisibility $visibility,
    ) {}

    public function index(string $reference): ApiResponse
    {
        $item = $this->findVisibleWorkItem($reference);

        $comments = CommentModel::query()
            ->with(['author.user:id,name,avatar_path', 'mentions'])
            ->where('commentable_type', 'work_item')
            ->where('commentable_id', $item->id)
            ->orderBy('created_at')
            ->get();

        return $this->ok(CommentResource::collection($comments));
    }

    public function store(Request $request, string $reference): ApiResponse
    {
        $item = $this->findVisibleWorkItem($reference);

        $this->authorize('comment', $item);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $comment = $this->comments->create(
            'work_item',
            (string) $item->id,
            $validated['body'],
            $validated['parent_id'] ?? null,
        );

        return $this->created(new CommentResource($comment->load('author.user')));
    }

    public function update(Request $request, string $id): ApiResponse
    {
        /** @var CommentModel $comment */
        $comment = CommentModel::query()->findOrFail($id);

        // Editing someone else's words is not a permission anyone gets: the
        // author is the only person who can change what they said. Removal is a
        // separate, moderated action.
        abort_unless(
            (string) $comment->author_membership_id
                === app(TenantContext::class)->membershipId(),
            403,
            'You can only edit your own comments.',
        );

        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:20000']]);

        return $this->ok(new CommentResource(
            $this->comments->update($comment, $validated['body'])->load('author.user')
        ));
    }

    private function findVisibleWorkItem(string $reference): WorkItemModel
    {
        $query = WorkItemModel::query()->where('reference', mb_strtoupper($reference));

        $this->visibility->apply($query);

        return $query->firstOrFail();
    }
}
