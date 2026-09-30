<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Infrastructure\Job\EvaluateWorkflowRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Announces deadlines to the rule engine: `schedule.due_soon` and
 * `schedule.overdue` (ADR 0057).
 *
 * Both triggers were offered by the rule builder since Phase 4 and dispatched
 * by nothing, so "when work goes overdue, tell the project owner" could be
 * written, saved and shown as active, and never ran once.
 *
 * **Crossings, not states.** Each deadline is announced once, when it is
 * crossed: due within DUE_SOON_HOURS, or past due within the last
 * LOOKBACK_HOURS. `work_item_deadline_signals` remembers what was announced,
 * keyed by the due date, so moving a deadline makes a new one.
 *
 * **The lookback is what keeps the first run quiet.** Work that has been
 * overdue for a week was overdue before any rule could ask about it; announcing
 * every such item the minute this ships would send one notification per late
 * item in every organization at once. Twenty-four hours covers a scheduler
 * that was down for a night and nothing older.
 *
 * Open work only — done and cancelled work has no deadline left to miss — and
 * not archived or deleted.
 */
final class DeadlineScanner
{
    public const DUE_SOON_HOURS = 24;

    public const LOOKBACK_HOURS = 24;

    /** Per signal, per run. The next tick takes the rest. */
    private const BATCH = 500;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly WorkItemFacts $facts,
    ) {}

    /** @return array{due_soon: int, overdue: int} how many were announced */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'due_soon' => $this->announce('due_soon', $now, $now->addHours(self::DUE_SOON_HOURS)),
            'overdue' => $this->announce('overdue', $now->subHours(self::LOOKBACK_HOURS), $now),
        ];
    }

    private function announce(string $signal, CarbonImmutable $from, CarbonImmutable $to): int
    {
        /** @var list<object{id: string, organization_id: string, due_at: string}> $candidates */
        $candidates = $this->tenant->runAsPlatform(
            "find work whose deadline was crossed ({$signal}) in every organization",
            fn (): array => DB::table('work_items as w')
                ->whereNull('w.deleted_at')
                ->whereNull('w.archived_at')
                ->whereNotIn('w.state_category', ['done', 'cancelled'])
                ->whereNotNull('w.due_at')
                // `overdue` is strictly past; `due_soon` starts now.
                ->where('w.due_at', $signal === 'overdue' ? '>=' : '>', $from)
                ->where('w.due_at', $signal === 'overdue' ? '<' : '<=', $to)
                ->whereNotExists(fn ($q) => $q->from('work_item_deadline_signals as s')
                    ->whereColumn('s.work_item_id', 'w.id')
                    ->where('s.signal', $signal)
                    ->whereColumn('s.due_at', 'w.due_at'))
                ->orderBy('w.due_at')
                ->limit(self::BATCH)
                ->get(['w.id', 'w.organization_id', 'w.due_at'])
                ->all(),
        );

        $announced = 0;

        foreach ($candidates as $item) {
            $announced += (int) $this->tenant->runFor((string) $item->organization_id, function () use ($item, $signal): bool {
                // The claim IS the dedupe: a second scan racing this one finds
                // the row and announces nothing.
                $claimed = DB::table('work_item_deadline_signals')->insertOrIgnore([
                    'organization_id' => $item->organization_id,
                    'work_item_id' => $item->id,
                    'signal' => $signal,
                    'due_at' => $item->due_at,
                    'fired_at' => now(),
                ]) === 1;

                if ($claimed) {
                    EvaluateWorkflowRules::dispatch(
                        (string) $item->organization_id,
                        "schedule.{$signal}",
                        'work_item',
                        (string) $item->id,
                        $this->facts->for((string) $item->id),
                    );
                }

                return $claimed;
            });
        }

        return $announced;
    }
}
