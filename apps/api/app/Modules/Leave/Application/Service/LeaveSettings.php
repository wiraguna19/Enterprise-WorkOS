<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Leave\Domain\Exception\LeaveRefused;
use App\Modules\Leave\Domain\LeavePreset;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\Uid\UuidV7;

/**
 * An organization's leave rules, its leave types and its holidays (ADR 0063).
 *
 * Audited rather than logged as activity: how much time off people are owed
 * is a question about them, asked by them, and "who changed the carry-over
 * and when" has to have an answer.
 */
final class LeaveSettings
{
    public const LEVELS = ['staff', 'supervisor', 'manager', 'director'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed>|null */
    public function policy(): ?array
    {
        /** @var stdClass|null $row */
        $row = DB::table('leave_policies')
            ->where('organization_id', $this->tenant->organizationId())
            ->first();

        return $row === null ? null : self::presentPolicy($row);
    }

    /**
     * Fill the policy and the leave types from a preset.
     *
     * Types are added by key and never overwritten: applying a preset twice,
     * or after HR renamed "Izin", must not undo their work.
     */
    public function applyPreset(string $preset, ?Request $request = null): void
    {
        if (! in_array($preset, LeavePreset::KEYS, strict: true)) {
            throw new LeaveRefused(__('There is no preset called :preset.', ['preset' => $preset]), ['refusal' => 'unknown_preset'], 422);
        }

        DB::transaction(function () use ($preset): void {
            $policy = LeavePreset::policy($preset);
            $organizationId = $this->tenant->organizationId();

            DB::table('leave_policies')->updateOrInsert(
                ['organization_id' => $organizationId],
                [
                    'preset' => $preset,
                    ...self::policyColumns($policy),
                    'updated_by_membership_id' => $this->tenant->membershipId(),
                    'updated_at' => now(),
                ],
            );

            $existing = DB::table('leave_types')
                ->where('organization_id', $organizationId)
                ->pluck('key')
                ->all();

            foreach (LeavePreset::types($preset) as $type) {
                if (in_array($type['key'], $existing, strict: true)) {
                    continue;
                }

                DB::table('leave_types')->insert([
                    'id' => (string) new UuidV7,
                    'organization_id' => $organizationId,
                    ...$type,
                ]);
            }
        });

        $this->audit->record('leave.preset_applied', ['preset' => $preset], $request);
    }

    /** @param array<string, mixed> $values */
    public function updatePolicy(array $values, ?Request $request = null): void
    {
        $organizationId = $this->tenant->organizationId();

        $exists = DB::table('leave_policies')->where('organization_id', $organizationId)->exists();

        DB::table('leave_policies')->updateOrInsert(
            ['organization_id' => $organizationId],
            [
                ...self::policyColumns($values),
                'updated_by_membership_id' => $this->tenant->membershipId(),
                'updated_at' => now(),
            ],
        );

        $this->audit->record($exists ? 'leave.policy_updated' : 'leave.policy_created', [
            'base_days' => $values['base_days'],
            'carry_over_max_days' => $values['carry_over_max_days'],
            'approval' => $values['approval'],
        ], $request);
    }

    /** @return list<array<string, mixed>> */
    public function types(bool $activeOnly = false): array
    {
        $query = DB::table('leave_types')
            ->where('organization_id', $this->tenant->organizationId())
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        /** @var list<stdClass> $rows */
        $rows = $query->get()->all();

        return array_map(self::presentType(...), $rows);
    }

    /** @param array<string, mixed> $values */
    public function createType(array $values, ?Request $request = null): string
    {
        $organizationId = $this->tenant->organizationId();

        $taken = DB::table('leave_types')
            ->where('organization_id', $organizationId)
            ->where('key', $values['key'])
            ->exists();

        if ($taken) {
            throw new LeaveRefused(
                __('There is already a leave type called :key.', ['key' => (string) $values['key']]),
                ['refusal' => 'key_taken'],
            );
        }

        $id = (string) new UuidV7;

        DB::table('leave_types')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'key' => $values['key'],
            ...self::typeColumns($values),
        ]);

        $this->audit->record('leave.type_created', ['key' => $values['key']], $request, targetType: 'leave_type', targetId: $id);

        return $id;
    }

    /**
     * Change a type. Its key is not changeable — requests and reports name it —
     * and a type is switched off rather than deleted, because requests made
     * under it must keep saying what they were.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateType(string $id, array $values, ?Request $request = null): void
    {
        $updated = DB::table('leave_types')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->update([...self::typeColumns($values), 'updated_at' => now()]);

        if ($updated === 0) {
            throw new LeaveRefused(__('Resource not found.'), ['refusal' => 'type_not_found'], 404);
        }

        $this->audit->record('leave.type_updated', ['changes' => array_keys($values)], $request, targetType: 'leave_type', targetId: $id);
    }

    /** @return list<array{id: string, on_date: string, name: string, kind: string}> */
    public function holidays(int $year): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('leave_holidays')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereBetween('on_date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('on_date')
            ->get()
            ->all();

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'on_date' => (string) $row->on_date,
            'name' => (string) $row->name,
            'kind' => (string) $row->kind,
        ], $rows);
    }

    public function addHoliday(string $date, string $name, string $kind, ?Request $request = null): string
    {
        $organizationId = $this->tenant->organizationId();

        if (DB::table('leave_holidays')->where('organization_id', $organizationId)->where('on_date', $date)->exists()) {
            throw new LeaveRefused(
                __('There is already a holiday on :date.', ['date' => $date]),
                ['refusal' => 'date_taken'],
            );
        }

        $id = (string) new UuidV7;

        DB::table('leave_holidays')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'on_date' => $date,
            'name' => trim($name),
            'kind' => $kind,
        ]);

        $this->audit->record('leave.holiday_added', ['on_date' => $date, 'name' => trim($name)], $request);

        return $id;
    }

    public function removeHoliday(string $id, ?Request $request = null): void
    {
        $row = DB::table('leave_holidays')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->first(['on_date', 'name']);

        if ($row === null) {
            throw new LeaveRefused(__('Resource not found.'), ['refusal' => 'holiday_not_found'], 404);
        }

        DB::table('leave_holidays')->where('id', $id)->delete();

        $this->audit->record('leave.holiday_removed', ['on_date' => (string) $row->on_date, 'name' => (string) $row->name], $request);
    }

    /**
     * Where this organization's settings fall below its preset's minimums.
     *
     * Warnings, never refusals: the rules are the company's, and so is the
     * decision to go below what a preset suggests. But a quota quietly below
     * the legal minimum is the kind of thing somebody should be told about
     * on the screen where they set it.
     *
     * @return list<array{code: string, minimum: float|int}>
     */
    public function warnings(): array
    {
        $policy = $this->policy();

        if ($policy === null || ! is_string($policy['preset'])) {
            return [];
        }

        $minimums = LeavePreset::minimums($policy['preset']);
        $warnings = [];

        if ((float) $policy['base_days'] < $minimums['base_days']) {
            $warnings[] = ['code' => 'base_days_below_minimum', 'minimum' => $minimums['base_days']];
        }

        $types = [];

        foreach ($this->types() as $type) {
            $types[(string) $type['key']] = $type;
        }

        foreach ($minimums['types'] as $key => $days) {
            $type = $types[$key] ?? null;

            if ($type === null || $type['is_active'] !== true) {
                $warnings[] = ['code' => "type_missing_{$key}", 'minimum' => $days];

                continue;
            }

            if ($key === 'sick' && $type['uses_quota'] === true) {
                $warnings[] = ['code' => 'sick_uses_quota', 'minimum' => 0];
            }

            if ($days > 0 && $type['max_days_per_request'] !== null && (int) $type['max_days_per_request'] < $days) {
                $warnings[] = ['code' => "type_short_{$key}", 'minimum' => $days];
            }
        }

        return $warnings;
    }

    /** @return array<string, mixed> */
    private static function presentPolicy(stdClass $row): array
    {
        return [
            'preset' => $row->preset,
            'period' => (string) $row->period,
            'accrual' => (string) $row->accrual,
            'base_days' => (float) $row->base_days,
            'probation_months' => (int) $row->probation_months,
            'carry_over_max_days' => (float) $row->carry_over_max_days,
            'carry_over_until_month' => $row->carry_over_until_month === null ? null : (int) $row->carry_over_until_month,
            'approval' => (string) $row->approval,
            'working_days' => self::intArray((string) $row->working_days),
            // Rebuilt rather than passed through: jsonb stores an object's keys
            // in its own order (shorter first), so `days` would come back
            // before `years`.
            'tenure_bonus' => array_map(
                static fn (array $band): array => ['years' => (int) $band['years'], 'days' => (float) $band['days']],
                (array) json_decode((string) $row->tenure_bonus, true, flags: JSON_THROW_ON_ERROR),
            ),
            'level_bonus' => (object) json_decode((string) $row->level_bonus, true, flags: JSON_THROW_ON_ERROR),
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private static function presentType(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'key' => (string) $row->key,
            'name' => (string) $row->name,
            'paid' => (bool) $row->paid,
            'uses_quota' => (bool) $row->uses_quota,
            'after_probation' => (bool) $row->after_probation,
            'day_basis' => (string) $row->day_basis,
            'max_days_per_request' => $row->max_days_per_request === null ? null : (int) $row->max_days_per_request,
            'attachment_after_days' => $row->attachment_after_days === null ? null : (int) $row->attachment_after_days,
            'allow_half_day' => (bool) $row->allow_half_day,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function policyColumns(array $values): array
    {
        /** @var list<int> $days */
        $days = array_values(array_unique(array_map('intval', (array) $values['working_days'])));
        sort($days);

        /** @var list<array{years: int|string, days: float|int|string}> $tenure */
        $tenure = array_values((array) $values['tenure_bonus']);
        usort($tenure, static fn (array $a, array $b): int => (int) $a['years'] <=> (int) $b['years']);

        $levels = [];

        foreach ((array) $values['level_bonus'] as $level => $bonus) {
            if (in_array($level, self::LEVELS, strict: true) && (float) $bonus > 0) {
                $levels[$level] = (float) $bonus;
            }
        }

        return [
            'period' => $values['period'],
            'accrual' => $values['accrual'],
            'base_days' => $values['base_days'],
            'probation_months' => $values['probation_months'],
            'carry_over_max_days' => $values['carry_over_max_days'],
            'carry_over_until_month' => $values['carry_over_until_month'] ?? null,
            'approval' => $values['approval'],
            'working_days' => '{'.implode(',', $days).'}',
            'tenure_bonus' => json_encode(array_map(static fn (array $band): array => [
                'years' => (int) $band['years'],
                'days' => (float) $band['days'],
            ], $tenure), JSON_THROW_ON_ERROR),
            'level_bonus' => json_encode((object) $levels, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function typeColumns(array $values): array
    {
        $columns = [];

        foreach (['name', 'paid', 'uses_quota', 'after_probation', 'day_basis', 'max_days_per_request', 'attachment_after_days', 'allow_half_day', 'is_active', 'sort_order'] as $column) {
            if (array_key_exists($column, $values)) {
                $columns[$column] = $column === 'name' ? trim((string) $values[$column]) : $values[$column];
            }
        }

        return $columns;
    }

    /** @return list<int> */
    private static function intArray(string $pgArray): array
    {
        $inner = trim($pgArray, '{}');

        return $inner === '' ? [] : array_map('intval', explode(',', $inner));
    }
}
