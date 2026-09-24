<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;
use Carbon\CarbonInterface;

/**
 * Writes for standing instructions to create work (ADR 0046).
 *
 * There is no `update()` here and there never will be, for the reason the
 * controller already gives: editing a rule that has produced work leaves the
 * items it made describing a schedule that no longer exists. Stopping is a
 * separate method from creating because it is a separate act — `deactivate()`
 * does not delete, since the work already created points back at this row.
 */
final class RecurrenceService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $template
     */
    public function create(
        string $rrule,
        array $template,
        CarbonInterface $firstOccurrence,
        ?string $endsAt,
    ): RecurrenceModel {
        $recurrence = new RecurrenceModel;

        $recurrence->forceFill([
            'id' => RecurrenceModel::newId(),
            'created_by_membership_id' => $this->tenant->membershipId(),
            'rrule' => $rrule,
            'template' => $template,
            'next_run_at' => $firstOccurrence,
            'ends_at' => $endsAt,
            'is_active' => true,
        ])->save();

        return $recurrence;
    }

    public function deactivate(RecurrenceModel $recurrence): RecurrenceModel
    {
        $recurrence->forceFill(['is_active' => false])->save();

        return $recurrence;
    }
}
