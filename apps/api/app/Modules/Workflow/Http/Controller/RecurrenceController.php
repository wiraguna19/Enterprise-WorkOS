<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controller;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Workflow\Http\Request\CreateRecurrenceRequest;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;

/**
 * Standing instructions to create work (docs/03 §4, docs/10 Phase 5).
 *
 * There is no PATCH. Editing a rule that has already produced work leaves the
 * items it made describing a schedule that no longer exists, and "why does this
 * say weekly when the rule says monthly" is unanswerable afterwards. Stopping
 * one and starting another keeps both stories intact — and stopping is
 * deactivation, not deletion, because the work already created points back here.
 */
final class RecurrenceController extends ApiController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(): ApiResponse
    {
        $recurrences = RecurrenceModel::query()
            ->orderByDesc('is_active')
            ->orderBy('next_run_at')
            ->get();

        return ApiResponse::collection(
            $recurrences->map(fn (RecurrenceModel $r): array => $this->present($r))->all(),
        );
    }

    public function store(CreateRecurrenceRequest $request): ApiResponse
    {
        $next = $request->firstOccurrenceAfterNow();

        $recurrence = new RecurrenceModel;
        $recurrence->forceFill([
            'id' => RecurrenceModel::newId(),
            'created_by_membership_id' => $this->tenant->membershipId(),
            'rrule' => $request->string('rrule')->toString(),
            'template' => $request->array('template'),
            // Validation has already proven there is one; the null-coalesce is
            // for the type checker, not for a case that can happen.
            'next_run_at' => $next ?? now()->addDay(),
            'ends_at' => $request->input('ends_at'),
            'is_active' => true,
        ])->save();

        return $this->created($this->present($recurrence));
    }

    /**
     * Stopping a recurrence, not erasing it.
     *
     * The work it already created carries `recurrence_id`, and that link is how
     * anyone answers "where did this come from" months later. Deleting the row
     * would leave those items pointing at nothing.
     */
    public function destroy(string $id): ApiResponse
    {
        /** @var RecurrenceModel $recurrence */
        $recurrence = RecurrenceModel::query()->findOrFail($id);

        $recurrence->forceFill(['is_active' => false])->save();

        return $this->noContent();
    }

    /** @return array<string, mixed> */
    private function present(RecurrenceModel $recurrence): array
    {
        return [
            'id' => (string) $recurrence->getKey(),
            'rrule' => $recurrence->rrule,
            'template' => $recurrence->template,
            'is_active' => $recurrence->is_active,
            'next_run_at' => $recurrence->is_active
                ? $recurrence->next_run_at->toIso8601String()
                : null,
            'last_run_at' => $recurrence->last_run_at?->toIso8601String(),
            'ends_at' => $recurrence->ends_at?->toIso8601String(),
            // What it has actually produced. A rule nobody can audit is a rule
            // nobody trusts (docs/02 §7).
            'created_count' => $recurrence->workItemCount(),
        ];
    }
}
