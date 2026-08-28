<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Domain\Exception\UnknownAction;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Runs the ACTION half of a rule.
 *
 * A closed registry, not a dynamic dispatch on a class name from the database.
 * That is the whole security posture of this class: a rule is customer-authored
 * data, and letting data name a class to instantiate is remote code execution
 * with extra steps. Adding an action is a code change and a migration —
 * deliberate friction (docs/12 §10).
 *
 * Each handler is responsible for its own idempotency. The queue redelivers,
 * and an action that fires twice must not produce two notifications or two
 * approvals (docs/01 §4).
 */
final class ActionExecutor
{
    /**
     * The closed set. Phase 4 ships five; `create_work_item` and `webhook`
     * are deliberately absent until Phase 7 — the first can generate unbounded
     * work through a loop the recursion guard cannot see, and the second lets a
     * rule reach the internet.
     *
     * @var array<string, class-string>
     */
    private const HANDLERS = [
        'notify' => Action\NotifyAction::class,
        'assign' => Action\AssignAction::class,
        'transition' => Action\TransitionAction::class,
        'create_approval' => Action\CreateApprovalAction::class,
        'escalate' => Action\EscalateAction::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    public function executeAll(
        array $actions,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        $results = [];

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if (! isset(self::HANDLERS[$type])) {
                // Unknown action types are a hard error, not a skip: a rule
                // that silently does nothing looks like it is working.
                throw new UnknownAction("Unknown workflow action: {$type}.", [
                    'action' => $type,
                    'available' => array_keys(self::HANDLERS),
                ]);
            }

            /** @var Action\WorkflowAction $handler */
            $handler = $this->container->make(self::HANDLERS[$type]);

            $outcome = $handler->execute(
                config: (array) ($action['with'] ?? []),
                subjectType: $subjectType,
                subjectId: $subjectId,
                facts: $facts,
                causationId: $causationId,
                depth: $depth,
            );

            $results[] = ['type' => $type] + $outcome;

            Log::debug('workflow.action_executed', [
                'type' => $type,
                'subject_id' => $subjectId,
                'causation_id' => $causationId,
                'depth' => $depth,
                'organization_id' => $this->tenant->organizationId(),
            ]);
        }

        return $results;
    }

    /** @return list<string> */
    public static function availableActions(): array
    {
        return array_keys(self::HANDLERS);
    }
}
