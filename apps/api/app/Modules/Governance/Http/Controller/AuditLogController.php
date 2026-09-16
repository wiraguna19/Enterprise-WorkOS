<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controller;

use App\Modules\Governance\Infrastructure\Eloquent\AuditLogModel;
use App\Modules\Platform\Application\Query\CursorPage;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Reading the security audit log (docs/10 Phase 7, ADR 0019).
 *
 * The table has been written since Phase 1 and read by nothing. Every question
 * it exists to answer — who let that person in, who changed that role, who
 * tried to sign in as somebody and failed — has been unanswerable through the
 * product for seven phases.
 *
 * Three filters and no more: WHEN, WHO, and WHAT KIND. They are the three
 * axes the table is indexed on, which is not a coincidence — a filter the
 * index cannot serve turns a partitioned table with millions of rows into a
 * sequential scan, and a screen that offers it is a screen that times out in
 * the one month somebody needs it most.
 */
final class AuditLogController extends ApiController
{
    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'event' => ['sometimes', 'string', 'max:80'],
            'actor' => ['sometimes', 'uuid'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
        ]);

        $query = AuditLogModel::query()
            // Prefix, not equality: `invitation.` is how somebody asks "what
            // happened with invitations", and the index on
            // (organization_id, event, occurred_at) serves a prefix.
            ->when(
                isset($validated['event']),
                fn (Builder $q): Builder => $q->where('event', 'like', $validated['event'].'%'),
            )
            ->when(
                isset($validated['actor']),
                fn (Builder $q): Builder => $q->where('actor_user_id', $validated['actor']),
            )
            ->when(
                isset($validated['since']),
                fn (Builder $q): Builder => $q->where('occurred_at', '>=', $validated['since']),
            )
            ->when(
                isset($validated['until']),
                fn (Builder $q): Builder => $q->where('occurred_at', '<=', $validated['until']),
            )
            // Newest first, and the id breaks the tie: two events in the same
            // millisecond are ordinary here — a rule firing writes several —
            // and a cursor built from a timestamp alone cannot say which side
            // of itself they fall on.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $page = new CursorPage(
            $query->cursorPaginate(CursorPage::perPage($request->integer('limit')))
        );

        return ApiResponse::collection(
            array_map($this->present(...), $page->paginator->items()),
            $page->meta(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AuditLogModel $entry): array
    {
        return [
            'id' => $entry->id,
            'event' => $entry->event,
            // The email AS IT WAS. The snapshot is the whole point of that
            // column: an audit trail that resolved the actor's name today would
            // rewrite history every time somebody changed theirs, and would say
            // nothing at all about an account since deleted.
            'actor' => $entry->actor_email_snapshot,
            'actor_user_id' => $entry->actor_user_id,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            // Already redacted at write time by AuditLogger — this endpoint
            // must not become the place where that is decided a second time.
            'metadata' => $entry->metadata,
            'ip_address' => $entry->ip_address,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
        ];
    }
}
