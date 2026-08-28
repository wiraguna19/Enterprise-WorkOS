<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controller;

use App\Modules\Calendar\Application\Query\CalendarQuery;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * `GET /calendar?from=&to=&sources=` (docs/05 §2).
 */
final class CalendarController extends ApiController
{
    /**
     * Six months. Wide enough for a quarter view with context either side,
     * narrow enough that a projected daily rule cannot expand into thousands of
     * events for a caller who passed `from=2020&to=2030`.
     */
    private const MAX_WINDOW_DAYS = 186;

    public function __construct(
        private readonly CalendarQuery $calendar,
    ) {}

    public function __invoke(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sources' => ['sometimes', 'string'],
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : CarbonImmutable::now()->startOfMonth();

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfDay()
            : $from->addMonth()->endOfMonth();

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $to = $from->addDays(self::MAX_WINDOW_DAYS);
        }

        $sources = $this->sources($request);

        return ApiResponse::collection(
            $this->calendar->between($from, $to, $sources),
            [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'sources' => $sources,
            ],
        );
    }

    /**
     * An unknown source is a 422, not a silent ignore — the same rule filters
     * follow (docs/05 §4).
     *
     * @return list<string>
     */
    private function sources(Request $request): array
    {
        if (! $request->filled('sources')) {
            return CalendarQuery::SOURCES;
        }

        $requested = array_values(array_filter(array_map(
            trim(...),
            explode(',', $request->string('sources')->toString()),
        )));

        $unknown = array_diff($requested, CalendarQuery::SOURCES);

        if ($unknown !== []) {
            abort(422, 'Unknown calendar source: '.implode(', ', $unknown).'.');
        }

        return $requested;
    }
}
