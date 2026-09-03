<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Report;

use Carbon\CarbonImmutable;

/**
 * The window a report covers, read the same way everywhere.
 *
 * The same defaulting `FlowController` applies to its own window — the last
 * quarter, ending this week. Written once rather than in each builder: four
 * copies of "what does `from` mean when it is absent" is four chances for a
 * report and the page it came from to describe different fortnights.
 */
final class ReportWindow
{
    private const DEFAULT_WEEKS = 12;

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function from(array $parameters): array
    {
        $to = isset($parameters['to'])
            ? CarbonImmutable::parse((string) $parameters['to'])->endOfDay()
            : CarbonImmutable::now()->endOfWeek();

        $from = isset($parameters['from'])
            ? CarbonImmutable::parse((string) $parameters['from'])->startOfDay()
            : $to->subWeeks(self::DEFAULT_WEEKS)->startOfWeek();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
