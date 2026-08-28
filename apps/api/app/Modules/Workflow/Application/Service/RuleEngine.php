<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * Trigger → Condition → Action (docs/02 §7).
 *
 * Three properties matter more than features here, and all three are about
 * failure rather than success:
 *
 *   1. **It terminates.** Two rules that trigger each other are the single
 *      most common way a workflow engine takes down a queue. Every change
 *      carries a causation chain, and the engine refuses to process one deeper
 *      than MAX_DEPTH — cheap to build on day one, very expensive to retrofit
 *      at 3am.
 *
 *   2. **It is observable.** Every evaluation is logged, including the ones
 *      that matched nothing. "Why didn't my rule fire?" is the first question
 *      anyone asks of any automation system, and it is unanswerable without a
 *      run log.
 *
 *   3. **It fails loudly per rule, not globally.** One broken rule must not
 *      stop the others, and a rule that keeps failing disables itself rather
 *      than retrying forever — a stuck rule silently degrades every workflow it
 *      touches, and a disabled one is visible.
 */
final class RuleEngine
{
    /**
     * A rule-caused change may itself trigger rules, five deep. Beyond that the
     * chain is almost certainly a loop rather than a legitimate cascade.
     */
    public const MAX_DEPTH = 5;

    /** Consecutive failures before a rule takes itself out of service. */
    private const FAILURE_THRESHOLD = 5;

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ActionExecutor $actions,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Evaluate every active rule for a trigger.
     *
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>> one summary per rule evaluated
     */
    public function dispatch(
        string $trigger,
        string $subjectType,
        string $subjectId,
        array $facts,
        ?string $causationId = null,
        int $depth = 0,
    ): array {
        $causationId ??= (string) new UuidV7;

        if ($depth > self::MAX_DEPTH) {
            // Recorded, not silently dropped: a chain that hits the ceiling is
            // a bug in someone's rules and they need to be able to see it.
            $this->logRun(
                ruleId: null,
                subjectType: $subjectType,
                subjectId: $subjectId,
                causationId: $causationId,
                depth: $depth,
                outcome: 'depth_exceeded',
                matched: false,
                actionsRun: [],
                error: 'Causation chain exceeded '.self::MAX_DEPTH.' levels; refusing to continue.',
                durationMs: 0,
            );

            Log::warning('workflow.recursion_guard_tripped', [
                'trigger' => $trigger,
                'subject_id' => $subjectId,
                'causation_id' => $causationId,
                'depth' => $depth,
            ]);

            return [];
        }

        $rules = WorkflowRuleModel::query()
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->when(
                isset($facts['workflow_id']),
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('workflow_id')
                    ->orWhere('workflow_id', $facts['workflow_id'])),
            )
            ->orderBy('run_order')
            ->get();

        $results = [];

        foreach ($rules as $rule) {
            $results[] = $this->runOne($rule, $subjectType, $subjectId, $facts, $causationId, $depth);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function runOne(
        WorkflowRuleModel $rule,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        $startedAt = microtime(true);

        try {
            $matched = $this->conditions->matches((array) $rule->conditions, $facts);

            if (! $matched) {
                $this->logRun(
                    ruleId: (string) $rule->id,
                    subjectType: $subjectType,
                    subjectId: $subjectId,
                    causationId: $causationId,
                    depth: $depth,
                    outcome: 'skipped',
                    matched: false,
                    actionsRun: [],
                    error: null,
                    durationMs: $this->elapsed($startedAt),
                );

                return ['rule' => $rule->name, 'outcome' => 'skipped'];
            }

            $ran = $this->actions->executeAll(
                array_values((array) $rule->actions),
                $subjectType,
                $subjectId,
                $facts,
                $causationId,
                $depth + 1,
            );

            // A rule that succeeds clears its failure count: the threshold is
            // for CONSECUTIVE failures, so a transient blip does not
            // accumulate toward disabling a healthy rule.
            if ($rule->failure_count > 0) {
                $rule->forceFill(['failure_count' => 0])->save();
            }

            $this->logRun(
                ruleId: (string) $rule->id,
                subjectType: $subjectType,
                subjectId: $subjectId,
                causationId: $causationId,
                depth: $depth,
                outcome: 'applied',
                matched: true,
                actionsRun: $ran,
                error: null,
                durationMs: $this->elapsed($startedAt),
            );

            return ['rule' => $rule->name, 'outcome' => 'applied', 'actions' => $ran];
        } catch (Throwable $e) {
            // One broken rule must not stop the others. The exception is
            // swallowed HERE and nowhere else — a swallowed approval-routing
            // failure is a data-integrity bug wearing a costume (docs/01 §4),
            // so it is recorded, counted, and alerted on.
            $this->recordFailure($rule, $e);

            $this->logRun(
                ruleId: (string) $rule->id,
                subjectType: $subjectType,
                subjectId: $subjectId,
                causationId: $causationId,
                depth: $depth,
                outcome: 'failed',
                matched: true,
                actionsRun: [],
                error: $e->getMessage(),
                durationMs: $this->elapsed($startedAt),
            );

            return ['rule' => $rule->name, 'outcome' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function recordFailure(WorkflowRuleModel $rule, Throwable $e): void
    {
        $failures = $rule->failure_count + 1;

        $attributes = ['failure_count' => $failures];

        if ($failures >= self::FAILURE_THRESHOLD) {
            $attributes['is_active'] = false;
            $attributes['disabled_reason'] = mb_substr(
                "Disabled after {$failures} consecutive failures: ".$e->getMessage(),
                0,
                200,
            );

            Log::error('workflow.rule_disabled', [
                'rule_id' => $rule->id,
                'rule' => $rule->name,
                'failures' => $failures,
                'error' => $e->getMessage(),
            ]);
        }

        $rule->forceFill($attributes)->save();
    }

    /** @param list<array<string, mixed>> $actionsRun */
    private function logRun(
        ?string $ruleId,
        string $subjectType,
        string $subjectId,
        string $causationId,
        int $depth,
        string $outcome,
        bool $matched,
        array $actionsRun,
        ?string $error,
        int $durationMs,
    ): void {
        // A rule id is required by the schema; a depth_exceeded run has no
        // single rule to blame, so it is attributed to the chain instead.
        if ($ruleId === null) {
            Log::info('workflow.rule_run', compact('subjectId', 'causationId', 'outcome'));

            return;
        }

        DB::table('workflow_rule_runs')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->organizationId(),
            'rule_id' => $ruleId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'causation_id' => $causationId,
            'causation_depth' => $depth,
            'outcome' => $outcome,
            'matched' => $matched,
            'actions_run' => json_encode($actionsRun, JSON_THROW_ON_ERROR),
            'error' => $error,
            'duration_ms' => $durationMs,
            'occurred_at' => now(),
        ]);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
