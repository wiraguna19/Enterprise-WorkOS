<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Kpi;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One week (from Monday), month or quarter, in UTC — the calendar the flow
 * report already uses (ADR 0062).
 */
final class KpiPeriod
{
    public const KINDS = ['week', 'month', 'quarter'];

    private function __construct(
        public readonly string $kind,
        public readonly CarbonImmutable $start,
    ) {}

    /** The period of this kind that contains the given moment. */
    public static function containing(string $kind, CarbonImmutable $moment): self
    {
        $moment = $moment->utc();

        return new self($kind, match ($kind) {
            'week' => $moment->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $moment->startOfMonth(),
            'quarter' => $moment->startOfQuarter(),
            default => throw new InvalidArgumentException("Unknown period {$kind}."),
        });
    }

    public static function current(string $kind): self
    {
        return self::containing($kind, CarbonImmutable::now());
    }

    /**
     * The last `$count` periods, oldest first, ending with the current one.
     *
     * @return list<self>
     */
    public static function lastFew(string $kind, int $count): array
    {
        $periods = [self::current($kind)];

        while (count($periods) < $count) {
            array_unshift($periods, $periods[0]->previous());
        }

        return $periods;
    }

    public function end(): CarbonImmutable
    {
        return match ($this->kind) {
            'week' => $this->start->addWeek(),
            'month' => $this->start->addMonthNoOverflow(),
            default => $this->start->addMonthsNoOverflow(3),
        };
    }

    public function previous(): self
    {
        return match ($this->kind) {
            'week' => new self($this->kind, $this->start->subWeek()),
            'month' => new self($this->kind, $this->start->subMonthNoOverflow()),
            default => new self($this->kind, $this->start->subMonthsNoOverflow(3)),
        };
    }

    /** Still running: its value is "so far", not final. */
    public function isCurrent(): bool
    {
        return CarbonImmutable::now()->lessThan($this->end());
    }

    public function key(): string
    {
        return $this->start->toDateString();
    }
}
