<?php

declare(strict_types=1);

namespace App\Modules\Work\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An hour someone actually spent (docs/03 §4).
 *
 * Deliberately thin. Time is the input to estimates-versus-actuals and to
 * capacity reporting, and every field beyond who/what/when/how-long is a field
 * someone has to fill in before they can log four hours — which is how time
 * tracking stops being used and the numbers stop meaning anything.
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_item_id
 * @property string $membership_id
 * @property string $hours
 * @property CarbonImmutable $logged_on
 * @property string $note
 * @property CarbonImmutable $created_at
 */
final class TimeEntryModel extends TenantModel
{
    protected $table = 'time_entries';

    /**
     * The table has `created_at` and no `updated_at`, and that is right: an
     * entry is a claim about a day, not a document that gets revised. Correcting
     * one means deleting it and logging what actually happened.
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'logged_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkItemModel, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItemModel::class, 'work_item_id');
    }

    /** @return BelongsTo<MembershipModel, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MembershipModel::class, 'membership_id');
    }
}
