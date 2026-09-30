<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Application\Service\BulkWorkItems;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /work-items/bulk` (docs/05 §1, §5; ADR 0055).
 *
 * The REQUEST is validated as a whole — a missing list, a 101st reference, an
 * assignee who is not an active member here — because those are mistakes in
 * what was asked, and they are the same mistake for every subject. What each
 * SUBJECT makes of the change is answered per subject, with 200, in the body.
 */
final class BulkWorkItemController extends ApiController
{
    public function __construct(
        private readonly BulkWorkItems $bulk,
    ) {}

    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'references' => ['required', 'array', 'min:1', 'max:'.BulkWorkItems::MAX_SUBJECTS],
            'references.*' => ['required', 'string', 'max:40'],
            'assignee_id' => ['sometimes', 'uuid'],
            // Nullable: null CLEARS the due date, which is a real bulk change
            // ("none of these has a deadline any more"), not a missing field.
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $setsDue = $request->exists('due_at');

        if (! isset($validated['assignee_id']) && ! $setsDue) {
            throw ValidationException::withMessages([
                'references' => 'Say what to change: an assignee, a due date, or both.',
            ]);
        }

        $assignee = null;

        if (isset($validated['assignee_id'])) {
            // Tenant-scoped: another organization's membership id is simply
            // not found here, and says the same as a made-up one.
            // `whereKey()->first()`, not `find()`: find() of an unknown-typed
            // value is typed as possibly a Collection, and the answer here is
            // one membership or none.
            $assignee = MembershipModel::query()->whereKey((string) $validated['assignee_id'])->first();

            if ($assignee === null || ! $assignee->isActive()) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'That person is not an active member of this organization.',
                ]);
            }
        }

        /** @var list<string> $references */
        $references = $validated['references'];
        $dueAt = $setsDue ? ($validated['due_at'] ?? null) : null;

        return $this->ok($this->bulk->apply(
            $references,
            $assignee,
            $setsDue,
            is_string($dueAt) ? $dueAt : null,
            $request,
        ));
    }
}
