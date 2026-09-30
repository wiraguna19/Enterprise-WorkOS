<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Exception\DomainException;
use App\Modules\Work\Application\Query\WorkItemVisibility;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemAssignmentModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One change, applied to many work items (docs/05 §5, ADR 0055).
 *
 * **Per subject, never all-or-nothing.** Fifty items selected from a list are
 * fifty separate decisions the policy makes — one may be closed, one in a
 * project this person cannot edit, one already starting after the new due
 * date. Refusing the other forty-nine for the fiftieth would make the control
 * useless on exactly the lists where it is worth having. Each subject gets its
 * own result, with the refusal's own code and sentence.
 *
 * **Through the same services a single edit uses.** A due date goes through
 * `WorkItemService::update()` and an assignee through
 * `AssignmentService::assign()`, so the activity entry, the notification to
 * the new assignee, the date check and the realtime push are the ones a
 * single change produces. A bulk path with its own UPDATE statement would be a
 * second implementation of every one of those, and the first to drift.
 *
 * **Authorised before anything is written.** Both abilities are checked for a
 * subject before either change is made to it, so the common refusal — "you may
 * not assign this one" — never leaves an item half-changed. What is NOT
 * atomic across the two actions is a failure after that (a domain refusal
 * from the second write): each service commits its own transaction and
 * dispatches its events on commit, and wrapping them in an outer transaction
 * would dispatch events for writes the outer one could still roll back.
 */
final class BulkWorkItems
{
    /** docs/05 §5: bulk operations are bounded. */
    public const MAX_SUBJECTS = 100;

    public function __construct(
        private readonly WorkItemVisibility $visibility,
        private readonly WorkItemService $workItems,
        private readonly AssignmentService $assignments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * `$setsDue` says whether the due date is part of the change at all: with
     * it true, a null `$dueAt` CLEARS the date rather than leaving it alone.
     *
     * @param  list<string>  $references
     * @return array{succeeded: int, failed: int, results: list<array{reference: string, ok: bool, changed: bool, error?: array{code: string, message: string}}>}
     */
    public function apply(
        array $references,
        ?MembershipModel $assignee,
        bool $setsDue,
        ?string $dueAt,
        ?Request $request = null,
    ): array {
        $results = [];

        foreach (array_values(array_unique(array_map('mb_strtoupper', $references))) as $reference) {
            $results[] = $this->applyTo($reference, $assignee, $setsDue, $dueAt);
        }

        $succeeded = count(array_filter($results, static fn (array $r): bool => $r['ok']));

        $this->audit->record('work_item.bulk_updated', [
            'references' => array_column($results, 'reference'),
            'assignee_membership_id' => $assignee?->getKey(),
            'due_at' => $setsDue ? ($dueAt ?? 'cleared') : null,
            'succeeded' => $succeeded,
            'failed' => count($results) - $succeeded,
        ], $request, targetType: 'work_item');

        return [
            'succeeded' => $succeeded,
            'failed' => count($results) - $succeeded,
            'results' => $results,
        ];
    }

    /**
     * @return array{reference: string, ok: bool, changed: bool, error?: array{code: string, message: string}}
     */
    private function applyTo(string $reference, ?MembershipModel $assignee, bool $setsDue, ?string $dueAt): array
    {
        $query = WorkItemModel::query()->where('reference', $reference);
        $this->visibility->apply($query);
        $item = $query->first();

        // The same answer for "does not exist" and "exists and you may not see
        // it", as GET /work-items/{reference} gives: a bulk endpoint must not be
        // a way to probe references one hundred at a time.
        if ($item === null) {
            return $this->refused($reference, 'work_item.not_found', "{$reference} does not exist, or you cannot see it.");
        }

        if ($assignee !== null && Gate::denies('assign', $item)) {
            return $this->refused($reference, 'work_item.forbidden', "You cannot assign {$reference}.");
        }

        if ($setsDue && Gate::denies('update', $item)) {
            return $this->refused($reference, 'work_item.forbidden', "You cannot change {$reference}.");
        }

        $changed = false;

        try {
            if ($setsDue && ! $this->sameDay($item, $dueAt)) {
                $this->workItems->update($item, ['due_at' => $dueAt]);
                $changed = true;
            }

            if ($assignee !== null && ! $this->alreadyHolds($item, $assignee)) {
                $this->assignments->assign($item, (string) $assignee->getKey());
                $changed = true;
            }
        } catch (DomainException $refusal) {
            return $this->refused($reference, $refusal->errorCode(), $refusal->getMessage());
        }

        return ['reference' => $reference, 'ok' => true, 'changed' => $changed];
    }

    /**
     * Already assigned to that person: success, not a refusal. The intent was
     * "these are hers", and for this one it is already true — reporting it as
     * a failure would put a red line in the result for nothing to fix.
     */
    private function alreadyHolds(WorkItemModel $item, MembershipModel $assignee): bool
    {
        return WorkItemAssignmentModel::query()
            ->where('work_item_id', $item->getKey())
            ->where('role', 'assignee')
            ->where('membership_id', $assignee->getKey())
            ->whereNull('unassigned_at')
            ->exists();
    }

    /**
     * Unchanged when the item is already due on that DAY. The form speaks in
     * days; a second write that moved 17:00 to 17:00 would still add a line
     * to the item's history saying its due date changed.
     */
    private function sameDay(WorkItemModel $item, ?string $dueAt): bool
    {
        if ($item->due_at === null || $dueAt === null) {
            return $item->due_at === null && $dueAt === null;
        }

        return $item->due_at->toDateString() === substr($dueAt, 0, 10);
    }

    /**
     * @return array{reference: string, ok: false, changed: false, error: array{code: string, message: string}}
     */
    private function refused(string $reference, string $code, string $message): array
    {
        return [
            'reference' => $reference,
            'ok' => false,
            'changed' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
