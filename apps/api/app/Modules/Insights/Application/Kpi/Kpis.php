<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Kpi;

use App\Modules\Insights\Domain\Exception\KpiRefused;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\Uid\UuidV7;

/**
 * Key performance indicators (ADR 0062): keeping them, and reading them with
 * their recent history and a status against the target.
 */
final class Kpis
{
    /** How far short of the target still counts as "at risk" rather than "off track". */
    public const TOLERANCE = 0.10;

    /** Periods shown in a list (a trend) and on a KPI's own page (a history). */
    public const LIST_PERIODS = 6;

    public const DETAIL_PERIODS = 12;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly KpiAuthority $authority,
        private readonly KpiMetrics $metrics,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $subjectType, ?string $subjectId): array
    {
        $rows = DB::table('kpis')
            ->where('organization_id', $this->tenant->organizationId())
            ->whereNull('archived_at')
            // Person KPIs are never listed here (ADR 0062, "Never a comparison").
            ->where('subject_type', '!=', 'person')
            ->when($subjectType !== null, fn (Builder $query): Builder => $query->where('subject_type', $subjectType))
            ->when($subjectId !== null, fn (Builder $query): Builder => $query->where('subject_id', $subjectId))
            ->orderBy('name')
            ->get();

        $kpis = [];

        foreach ($rows as $row) {
            if ($this->authority->maySee((string) $row->subject_type, (string) $row->subject_id)) {
                $kpis[] = $this->present($row, self::LIST_PERIODS);
            }
        }

        return $kpis;
    }

    /** @return array<string, mixed> */
    public function show(string $id): array
    {
        return $this->present($this->visible($id), self::DETAIL_PERIODS);
    }

    /**
     * @param  array{name: string, description?: string|null, subject_type: string, subject_id: string, source: string, unit?: string|null, direction?: string|null, target: float|int|string, period: string}  $input
     */
    public function create(array $input): string
    {
        $type = $input['subject_type'];
        $subjectId = $input['subject_id'];

        if (! $this->subjectExists($type, $subjectId) || ! $this->authority->mayManage($type, $subjectId)) {
            throw new AuthorizationException('You cannot keep KPIs for this team, department or project.');
        }

        $source = $input['source'];
        $computed = $source !== 'manual';

        if (! $computed && ($input['direction'] ?? null) === null) {
            throw new KpiRefused('Say whether a higher or a lower value is better.', ['refusal' => 'direction_required']);
        }

        $id = (string) new UuidV7;
        $now = now();

        DB::table('kpis')->insert([
            'id' => $id,
            'organization_id' => $this->tenant->organizationId(),
            'name' => trim($input['name']),
            'description' => trim((string) ($input['description'] ?? '')),
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'source' => $source,
            // A computed KPI's unit and direction are what the number IS; they
            // are not the creator's to choose.
            'unit' => $computed ? KpiMetrics::UNITS[$source] : trim((string) ($input['unit'] ?? '')),
            'direction' => $computed ? KpiMetrics::DIRECTIONS[$source] : (string) $input['direction'],
            'target' => (float) $input['target'],
            'period' => $input['period'],
            'created_by_membership_id' => $this->tenant->membershipId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * The words and the target. Never the subject, the source or the period:
     * changing any of them would make the history mean something else.
     *
     * @param  array{name?: string, description?: string|null, unit?: string|null, direction?: string, target?: float|int|string}  $input
     */
    public function update(string $id, array $input): void
    {
        $row = $this->managed($id);
        $computed = $row->source !== 'manual';
        $changes = ['updated_at' => now()];

        if (array_key_exists('name', $input)) {
            $changes['name'] = trim($input['name']);
        }

        if (array_key_exists('description', $input)) {
            $changes['description'] = trim((string) $input['description']);
        }

        if (array_key_exists('target', $input)) {
            $changes['target'] = (float) $input['target'];
        }

        foreach (['unit', 'direction'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            if ($computed) {
                throw new KpiRefused(
                    'A computed KPI\'s unit and direction come from what it measures and cannot be changed.',
                    ['refusal' => 'computed_definition'],
                );
            }

            $changes[$field] = trim((string) $input[$field]);
        }

        DB::table('kpis')->where('id', $id)->update($changes);
    }

    /** Archived, not deleted: the history of a dropped target is still history. */
    public function archive(string $id): void
    {
        $this->managed($id);

        DB::table('kpis')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
    }

    /** Record (or correct) the value of a manual KPI for one period. */
    public function record(string $id, string $date, float $value, ?string $note): void
    {
        $row = $this->managed($id);

        if ($row->source !== 'manual') {
            throw new KpiRefused(
                'This KPI is computed from the work itself; there is nothing to enter.',
                ['refusal' => 'computed'],
            );
        }

        $period = KpiPeriod::containing((string) $row->period, CarbonImmutable::parse($date, 'UTC'));

        if ($period->start->greaterThan(KpiPeriod::current((string) $row->period)->start)) {
            throw new KpiRefused('A value cannot be recorded for a period that has not started.', ['refusal' => 'future_period']);
        }

        DB::table('kpi_entries')->upsert(
            [[
                'id' => (string) new UuidV7,
                'organization_id' => $this->tenant->organizationId(),
                'kpi_id' => $id,
                'period_start' => $period->key(),
                'value' => $value,
                'note' => trim((string) $note),
                'entered_by_membership_id' => $this->tenant->membershipId(),
                'entered_at' => now(),
            ]],
            ['kpi_id', 'period_start'],
            ['value', 'note', 'entered_by_membership_id', 'entered_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(stdClass $row, int $periods): array
    {
        $kind = (string) $row->period;
        $target = (float) $row->target;
        $direction = (string) $row->direction;
        $source = (string) $row->source;
        $subjectType = (string) $row->subject_type;
        $subjectId = (string) $row->subject_id;

        $entries = [];

        if ($source === 'manual') {
            foreach (DB::table('kpi_entries')->where('kpi_id', $row->id)->get(['period_start', 'value', 'note']) as $entry) {
                $entries[substr((string) $entry->period_start, 0, 10)] = $entry;
            }
        }

        $departments = $subjectType === 'department' ? $this->departmentAndBelow($subjectId) : null;
        $history = [];

        foreach (KpiPeriod::lastFew($kind, $periods) as $period) {
            $entry = $entries[$period->key()] ?? null;

            $value = $source === 'manual'
                ? ($entry === null ? null : (float) $entry->value)
                : $this->metrics->value($source, $subjectType, $subjectId, $period, $departments);

            $history[] = [
                'period_start' => $period->key(),
                'period_end' => $period->end()->subDay()->toDateString(),
                'partial' => $period->isCurrent(),
                'value' => $value,
                'status' => self::status($value, $target, $direction),
                'note' => $entry === null || (string) $entry->note === '' ? null : (string) $entry->note,
            ];
        }

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => (string) $row->description,
            'subject' => $this->subject($subjectType, $subjectId),
            'source' => $source,
            'unit' => (string) $row->unit,
            'direction' => $direction,
            'target' => $target,
            'period' => $kind,
            'archived' => $row->archived_at !== null,
            'current' => $history[count($history) - 1],
            'history' => $history,
            'can_manage' => $this->authority->mayManage($subjectType, $subjectId),
        ];
    }

    /** On track, at risk or off track against the target, or no data (ADR 0062). */
    public static function status(?float $value, float $target, string $direction): string
    {
        if ($value === null) {
            return 'no_data';
        }

        $margin = abs($target) * self::TOLERANCE;

        if ($direction === 'higher') {
            return $value >= $target ? 'on_track' : ($value >= $target - $margin ? 'at_risk' : 'off_track');
        }

        return $value <= $target ? 'on_track' : ($value <= $target + $margin ? 'at_risk' : 'off_track');
    }

    private function find(string $id): stdClass
    {
        $row = DB::table('kpis')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->first();

        if (! $row instanceof stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /** A KPI this person may see; anything else does not exist to them. */
    private function visible(string $id): stdClass
    {
        $row = $this->find($id);

        if ($row->subject_type === 'person' || ! $this->authority->maySee((string) $row->subject_type, (string) $row->subject_id)) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    private function managed(string $id): stdClass
    {
        $row = $this->visible($id);

        if (! $this->authority->mayManage((string) $row->subject_type, (string) $row->subject_id)) {
            throw new AuthorizationException('You cannot change the KPIs of this team, department or project.');
        }

        if ($row->archived_at !== null) {
            throw new KpiRefused('This KPI is archived.', ['refusal' => 'archived']);
        }

        return $row;
    }

    /** @return array{type: string, id: string, name: string|null, key: string|null} */
    private function subject(string $type, string $id): array
    {
        $table = match ($type) {
            'team' => 'teams',
            'department' => 'departments',
            'project' => 'projects',
            default => null,
        };

        $row = $table === null ? null : DB::table($table)
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->first($type === 'project' ? ['name', 'key'] : ['name']);

        return [
            'type' => $type,
            'id' => $id,
            'name' => $row instanceof stdClass ? (string) $row->name : null,
            'key' => $row instanceof stdClass && $type === 'project' ? (string) $row->key : null,
        ];
    }

    private function subjectExists(string $type, string $id): bool
    {
        $table = match ($type) {
            'team' => 'teams',
            'department' => 'departments',
            'project' => 'projects',
            default => null,
        };

        return $table !== null && DB::table($table)
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $id)
            ->whereNull('archived_at')
            ->exists();
    }

    /** @return list<string> */
    private function departmentAndBelow(string $departmentId): array
    {
        $organizationId = $this->tenant->organizationId();

        $path = DB::table('departments')
            ->where('organization_id', $organizationId)
            ->where('id', $departmentId)
            ->value('path');

        if ($path === null) {
            return [];
        }

        return array_values(DB::table('departments')
            ->where('organization_id', $organizationId)
            ->where('path', 'like', ((string) $path).'%')
            ->pluck('id')
            ->map(strval(...))
            ->all());
    }
}
