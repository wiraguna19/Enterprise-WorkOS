<?php

declare(strict_types=1);

namespace App\Modules\Work\Http\Controller;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Work\Infrastructure\Eloquent\TimeEntryModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * One person's own timesheet (docs/03 §4, docs/08 §3).
 *
 * Time is logged against a work item; this reads it back along the other axis —
 * by day, for one person — which is the shape a timesheet has and the shape no
 * per-item endpoint can produce.
 *
 * Deliberately not a general report: it answers only for the caller. "How many
 * hours did Sarah log" is a different question with a different audience and a
 * different permission, and it does not get to reuse this route by passing an
 * id (docs/06 §2).
 */
final class MyTimeController extends ApiController
{
    /**
     * A quarter, near enough. Long enough for "what did I do last month",
     * short enough that the response stays one page and one query.
     */
    private const MAX_WINDOW_DAYS = 100;

    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): ApiResponse
    {
        [$from, $to] = $this->window($request);

        $entries = TimeEntryModel::query()
            // The work item is what makes an entry mean anything: "3h" is not a
            // timesheet line, "3h on ENG-142" is.
            ->with('workItem:id,reference,title,state_category')
            ->where('membership_id', $this->tenant->membershipId())
            ->whereBetween('logged_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('logged_on')
            ->orderBy('created_at')
            ->get();

        // Grouped server-side because the client would have to do exactly this
        // to render anything, and the day totals are the numbers people check.
        $days = $entries
            ->groupBy(static fn (TimeEntryModel $entry): string => $entry->logged_on->toDateString())
            ->map(static fn ($group, string $date): array => [
                'date' => $date,
                'hours' => round((float) $group->sum(static fn (TimeEntryModel $e): float => (float) $e->hours), 2),
                'entries' => $group->map(static fn (TimeEntryModel $entry): array => [
                    'id' => (string) $entry->getKey(),
                    'hours' => (float) $entry->hours,
                    'note' => (string) $entry->note,
                    'logged_at' => $entry->created_at->toIso8601String(),
                    'work_item' => $entry->workItem === null ? null : [
                        'reference' => (string) $entry->workItem->reference,
                        'title' => (string) $entry->workItem->title,
                        'state_category' => (string) $entry->workItem->state_category,
                    ],
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::collection($days, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_hours' => round((float) $entries->sum(static fn (TimeEntryModel $e): float => (float) $e->hours), 2),
            // Days with something on them, not days in the range: "logged on 3
            // of the last 7" is the sentence someone wants out of this.
            'days_logged' => count($days),
        ]);
    }

    /**
     * The window, clamped.
     *
     * A timesheet's default is the current week because that is the one people
     * are filling in; anything else is a deliberate ask.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $today = CarbonImmutable::today();

        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : $today->startOfWeek();

        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->startOfDay()
            : $from->addDays(6);

        // A reversed range is a client bug, and returning nothing for it looks
        // like "you logged no time" — which is a different, alarming answer.
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $to = $from->addDays(self::MAX_WINDOW_DAYS);
        }

        return [$from, $to];
    }
}
