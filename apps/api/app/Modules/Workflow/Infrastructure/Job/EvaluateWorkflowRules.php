<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Job;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Application\Service\RuleEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rule evaluation, on the queue (docs/01 §4).
 *
 * Queued rather than inline because the user must not wait for automation to
 * finish: a status change should return as soon as the row is written, not
 * after four rules have notified six people. The trade-off — rules apply
 * moments later — is the right one, and the UI never implies otherwise.
 *
 * The job carries its tenant explicitly. A queued job has no request, so it has
 * no ambient tenant context, and a job that guesses would be the widest
 * possible cross-tenant hole (docs/01 §6).
 */
final class EvaluateWorkflowRules implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts. Rule evaluation reads a lot and writes a little, so a
     * transient database blip is worth retrying; a genuine bug is not, and the
     * engine's own failure counter handles that.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    /**
     * A rule cascade that has not finished in two minutes is not going to.
     * Without this a runaway chain holds a worker indefinitely.
     */
    public int $timeout = 120;

    /** @param array<string, mixed> $facts */
    public function __construct(
        private readonly string $organizationId,
        private readonly string $trigger,
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly array $facts,
        private readonly ?string $causationId = null,
        private readonly int $depth = 0,
    ) {
        $this->onQueue('default');
    }

    public function handle(TenantContext $tenant, RuleEngine $engine): void
    {
        $tenant->runFor($this->organizationId, function () use ($engine): void {
            $results = $engine->dispatch(
                trigger: $this->trigger,
                subjectType: $this->subjectType,
                subjectId: $this->subjectId,
                facts: $this->facts,
                causationId: $this->causationId,
                depth: $this->depth,
            );

            $applied = array_filter($results, static fn (array $r): bool => $r['outcome'] === 'applied');

            if ($applied !== []) {
                Log::info('workflow.rules_applied', [
                    'trigger' => $this->trigger,
                    'subject_id' => $this->subjectId,
                    'applied' => count($applied),
                    'depth' => $this->depth,
                ]);
            }
        });
    }

    /**
     * Deduplicate by (trigger, subject, causation).
     *
     * Two events for the same change — a retry, or a double-dispatched
     * listener — must evaluate the rules once. Without this the notify actions
     * fire twice and the user gets two emails, which is the failure everyone
     * remembers (docs/01 §4).
     */
    public function uniqueId(): string
    {
        return implode(':', [
            $this->organizationId,
            $this->trigger,
            $this->subjectId,
            $this->causationId ?? 'root',
            (string) $this->depth,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        // Reaching here means all retries are exhausted. Rules do not silently
        // disappear: this is an alertable condition, because the workflow the
        // customer configured is now not happening.
        Log::error('workflow.rule_evaluation_failed', [
            'organization_id' => $this->organizationId,
            'trigger' => $this->trigger,
            'subject_id' => $this->subjectId,
            'causation_id' => $this->causationId,
            'error' => $e->getMessage(),
        ]);
    }
}
