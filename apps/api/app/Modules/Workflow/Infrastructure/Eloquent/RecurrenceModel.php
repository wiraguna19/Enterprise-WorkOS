<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Eloquent;

use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A rule for creating work again and again (docs/03 §4).
 *
 * Column types below are hand-maintained: the schema is raw SQL (docs/03 §0),
 * so nothing can introspect it. Add a column here when you add one there, or
 * PHPStan loses the ability to tell a typo from a real field.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $created_by_membership_id
 * @property string $rrule
 * @property array<string, mixed> $template
 * @property CarbonImmutable $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RecurrenceModel extends TenantModel
{
    protected $table = 'recurrences';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template' => 'array',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** How much work this rule has actually produced. */
    public function workItemCount(): int
    {
        return DB::table('work_items')
            ->where('recurrence_id', $this->getKey())
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Has this recurrence run out of future?
     *
     * Separate from `is_active` on purpose: expiry is a fact about the rule,
     * deactivation is a decision someone made. Collapsing them would lose which
     * of the two stopped the work appearing.
     */
    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at);
    }
}
