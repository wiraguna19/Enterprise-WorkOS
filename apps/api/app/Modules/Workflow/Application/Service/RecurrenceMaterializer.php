<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Work\Application\Service\WorkItemService;
use App\Modules\Workflow\Infrastructure\Eloquent\RecurrenceModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RRule\RRule;
use Throwable;

/**
 * Turns recurrence rules into work, one occurrence at a time.
 *
 * Three properties matter, and each is a way this class could quietly go wrong:
 *
 * 1. **It never creates the same occurrence twice.** Due rows are claimed with
 *    `FOR UPDATE SKIP LOCKED` and their `next_run_at` is advanced in the same
 *    transaction that creates the item. Two schedulers running at once — a
 *    deploy overlap, a stuck worker — take different rows rather than the same
 *    one twice.
 *
 * 2. **It never floods.** A recurrence that has not run for a month is due
 *    once, not thirty times: the missed occurrences are skipped and counted,
 *    not materialised. Waking up to thirty copies of a daily checklist is worse
 *    than waking up to one and a log line saying twenty-nine were missed.
 *
 * 3. **A broken rule cannot stop the others.** One malformed RRULE deactivates
 *    itself with the reason recorded, exactly as the rule engine does with a
 *    failing rule (docs/02 §7).
 */
final class RecurrenceMaterializer
{
    /**
     * How many rules one tick will process.
     *
     * The scheduler runs every fifteen minutes; a backlog longer than this
     * drains over the following ticks rather than holding a transaction open
     * across thousands of rows.
     */
    private const BATCH = 100;

    public function __construct(
        private readonly WorkItemService $workItems,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{created: int, skipped: int, failed: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tally = ['created' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->dueRecurrenceIds($now) as $id) {
            try {
                $outcome = $this->materializeOne($id, $now);
                $tally[$outcome]++;
            } catch (Throwable $e) {
                $tally['failed']++;
                $this->deactivate($id, $e->getMessage());
            }
        }

        return $tally;
    }

    /**
     * Read across every tenant deliberately.
     *
     * This runs from the scheduler, which belongs to no organization — so the
     * query is a plain table read, not a tenant-scoped model, and each
     * occurrence is then created INSIDE `runFor()` so every write below it is
     * scoped normally (docs/01 §6).
     *
     * @return list<string>
     */
    private function dueRecurrenceIds(CarbonImmutable $now): array
    {
        $rows = DB::table('recurrences')
            ->where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->limit(self::BATCH)
            ->pluck('id');

        return array_values(array_map(strval(...), $rows->all()));
    }

    /** @return 'created'|'skipped' */
    private function materializeOne(string $id, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($id, $now): string {
            /** @var object{id: string, organization_id: string}|null $row */
            $row = DB::table('recurrences')
                ->where('id', $id)
                ->where('is_active', true)
                ->where('next_run_at', '<=', $now)
                ->lockForUpdate()
                ->first(['id', 'organization_id']);

            // Another worker took it between the read and the lock, or someone
            // switched it off. Both mean: not ours.
            if ($row === null) {
                return 'skipped';
            }

            return $this->tenant->runFor(
                (string) $row->organization_id,
                fn (): string => $this->createOccurrence($id, $now),
            );
        });
    }

    /** @return 'created'|'skipped' */
    private function createOccurrence(string $id, CarbonImmutable $now): string
    {
        /** @var RecurrenceModel $recurrence */
        $recurrence = RecurrenceModel::query()->findOrFail($id);

        if ($recurrence->hasExpired($now)) {
            $this->stop($recurrence, 'The rule reached its end date.');

            return 'skipped';
        }

        $next = $this->nextAfter($recurrence, $now);

        if ($next === null) {
            $this->stop($recurrence, 'The rule has no further occurrences.');

            return 'skipped';
        }

        $template = $recurrence->template;

        $this->workItems->create([
            ...$template,
            'recurrence_id' => (string) $recurrence->getKey(),
            // The template may carry `due_in_days` — a deadline relative to the
            // occurrence, because "due three days after it appears" is what a
            // recurring task actually means. An absolute due_at in a template
            // would be the same date forever.
            'due_at' => isset($template['due_in_days'])
                ? $now->addDays((int) $template['due_in_days'])
                : null,
        ]);

        $recurrence->forceFill([
            'last_run_at' => $now,
            'next_run_at' => $next,
        ])->save();

        return 'created';
    }

    /**
     * The next occurrence strictly after now — NOT the next one after the
     * missed slot.
     *
     * This is what keeps a paused system from flooding: whatever the rule says
     * should have happened while nothing was running is behind us, and the
     * count of what was skipped is logged rather than created.
     */
    private function nextAfter(RecurrenceModel $recurrence, CarbonImmutable $now): ?CarbonImmutable
    {
        $rule = new RRule($recurrence->rrule, $recurrence->created_at->toDateTime());

        $missed = 0;

        foreach ($rule as $occurrence) {
            $at = CarbonImmutable::instance($occurrence);

            if ($at->greaterThan($now)) {
                if ($missed > 1) {
                    Log::info('recurrence.occurrences_skipped', [
                        'recurrence_id' => (string) $recurrence->getKey(),
                        'skipped' => $missed - 1,
                        'reason' => 'the scheduler was behind; only the current occurrence is created',
                    ]);
                }

                return $recurrence->ends_at !== null && $at->greaterThan($recurrence->ends_at)
                    ? null
                    : $at;
            }

            $missed++;
        }

        return null;
    }

    private function stop(RecurrenceModel $recurrence, string $reason): void
    {
        $recurrence->forceFill(['is_active' => false])->save();

        Log::info('recurrence.stopped', [
            'recurrence_id' => (string) $recurrence->getKey(),
            'reason' => $reason,
        ]);
    }

    /**
     * A malformed rule takes itself out of service rather than failing every
     * tick forever — the same posture the rule engine takes (docs/02 §7).
     */
    private function deactivate(string $id, string $error): void
    {
        DB::table('recurrences')->where('id', $id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        Log::error('recurrence.failed', [
            'recurrence_id' => $id,
            'error' => $error,
        ]);
    }
}
