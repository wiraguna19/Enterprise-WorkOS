<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Kpi;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What one person finished, and how much of it was on time — without a target
 * (ADR 0062, amended: "Delivery without a KPI").
 *
 * The same visibility as a person KPI and for the same reason: the person, and
 * the people above them in the reporting line. Nobody else, and never in a list
 * of people.
 *
 * "Finished" counts ITEMS — each item once, in the week its last completion
 * fell — so that the number is exactly the length of the list behind it. A KPI's
 * throughput counts every move into Done, so a reopened item counts twice
 * there; both are stated where they are shown.
 */
final class PersonDelivery
{
    /** Weeks in the trend. */
    public const WEEKS = 12;

    /** Weeks in the summary figures (the last four, this one included). */
    public const SUMMARY_WEEKS = 4;

    public function __construct(
        private readonly KpiAuthority $authority,
        private readonly KpiMetrics $metrics,
    ) {}

    /**
     * @return array{summary: array{from: string, to: string, finished: int, dated: int, on_time: int, on_time_rate: float|null}, weeks: list<array{period_start: string, partial: bool, finished: int, dated: int, on_time: int, on_time_rate: float|null}>}
     */
    public function overview(string $membershipId): array
    {
        $this->authorize($membershipId);

        $periods = KpiPeriod::lastFew('week', self::WEEKS);
        $rows = $this->metrics->completions('person', $membershipId, $periods[0]->start, $periods[count($periods) - 1]->end());

        $weeks = [];

        foreach ($periods as $period) {
            $inWeek = array_values(array_filter($rows, static fn (array $row): bool => $period->start->lessThanOrEqualTo(CarbonImmutable::parse($row['completed_at']))
                && CarbonImmutable::parse($row['completed_at'])->lessThan($period->end())));

            $weeks[] = ['period_start' => $period->key(), 'partial' => $period->isCurrent(), ...self::fold($inWeek)];
        }

        $summaryFrom = $periods[count($periods) - self::SUMMARY_WEEKS]->start;
        $recent = array_values(array_filter($rows, static fn (array $row): bool => $summaryFrom->lessThanOrEqualTo(CarbonImmutable::parse($row['completed_at']))));

        return [
            'summary' => [
                'from' => $summaryFrom->toDateString(),
                'to' => $periods[count($periods) - 1]->end()->subDay()->toDateString(),
                ...self::fold($recent),
            ],
            'weeks' => $weeks,
        ];
    }

    /**
     * The items behind the figures, for a window of whole days.
     *
     * @return list<array{work_item_id: string, reference: string, title: string, completed_at: string, due_at: string|null, hours: float|null, late: bool|null}>
     */
    public function items(string $membershipId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->authorize($membershipId);

        return $this->metrics->completions('person', $membershipId, $from->startOfDay(), $to->addDay()->startOfDay());
    }

    private function authorize(string $membershipId): void
    {
        if (! $this->authority->maySee('person', $membershipId)) {
            throw new ModelNotFoundException;
        }
    }

    /**
     * @param  list<array{late: bool|null}>  $rows
     * @return array{finished: int, dated: int, on_time: int, on_time_rate: float|null}
     */
    private static function fold(array $rows): array
    {
        $dated = 0;
        $onTime = 0;

        foreach ($rows as $row) {
            if ($row['late'] === null) {
                continue;
            }

            $dated++;
            $onTime += $row['late'] ? 0 : 1;
        }

        return [
            'finished' => count($rows),
            'dated' => $dated,
            'on_time' => $onTime,
            'on_time_rate' => $dated === 0 ? null : round($onTime / $dated * 100, 1),
        ];
    }
}
