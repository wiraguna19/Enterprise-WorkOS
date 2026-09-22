<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowRuleModel;
use Illuminate\Http\Request;

/**
 * Trying a rule against one work item, by hand (ADR 0035).
 *
 * `workflow.run_rule` has been in the catalogue since Phase 3, ticked in the
 * role builder, and consulted by nothing — the last entry on the bill in
 * `EveryPermissionMeansSomethingTest`, whose note read: "the rule screens show
 * what a rule DID and cannot make it run."
 *
 * The question this answers is the first one anybody asks of any automation:
 * **why didn't my rule fire?** The run log answers it for rules that were
 * triggered. It cannot answer it for the rule somebody just wrote, because
 * nothing has happened to it yet — and the alternative is what people actually
 * do, which is to go and break a real work item to see.
 *
 * Two steps, and the first is the important one:
 *
 * - **Preview** evaluates the conditions and reports what WOULD run. It changes
 *   nothing and is not written to the run log, because that log records what
 *   the system did and a preview did nothing.
 * - **Run** is the engine's own path — same conditions, same actions, same
 *   failure counting, same log — because a "try it" that took a different path
 *   would be testing the button rather than the rule.
 */
final class ManualRuleRun
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly WorkItemFacts $facts,
        private readonly RuleEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What this rule would do to this item, without doing it.
     *
     * @return array{matched: bool, actions: list<array<string, mixed>>, unavailable_facts: list<string>}
     */
    public function preview(WorkflowRuleModel $rule, string $workItemId): array
    {
        $facts = $this->facts->for($workItemId);
        $conditions = (array) $rule->conditions;

        // Named before the verdict, because they change what the verdict MEANS
        // (ADR 0035). A rule whose condition asks "did it just move to In
        // Review" cannot be answered by an item sitting still, and reporting a
        // confident "did not match" would send somebody rewriting a rule that
        // was never wrong.
        $unavailable = $this->facts->momentOnlyFieldsIn($conditions);

        return [
            'matched' => $this->conditions->matches($conditions, $facts),
            'actions' => $this->conditions->matches($conditions, $facts)
                ? array_values((array) $rule->actions)
                : [],
            'unavailable_facts' => $unavailable,
        ];
    }

    /**
     * Run it for real, against that one item.
     *
     * @return array<string, mixed>
     */
    public function run(
        WorkflowRuleModel $rule,
        string $workItemId,
        string $membershipId,
        Request $request,
    ): array {
        $result = $this->engine->runNow(
            $rule,
            'work_item',
            $workItemId,
            $this->facts->for($workItemId),
            $membershipId,
        );

        // Audited as well as logged. The run log says what the ENGINE did; the
        // audit log says who asked it to, and those are different questions
        // asked by different people at different times (ADR 0019).
        $this->audit->record('workflow.rule_run_by_hand', [
            'rule_id' => (string) $rule->id,
            'rule_name' => $rule->name,
            'work_item_id' => $workItemId,
            'outcome' => $result['outcome'] ?? 'unknown',
        ], $request);

        return $result;
    }

    /**
     * The item a reference names, inside this tenant, or null.
     *
     * Through the MODEL, not `DB::table`. The first version used the query
     * builder and a test caught what that costs: the builder carries no
     * organization scope, so a reference from another tenant resolved happily
     * and this endpoint became a way to ask whether somebody else's work item
     * exists. The scope is the mechanism this codebase already has for that
     * question — reaching past it to save an import is how a leak gets written
     * by somebody who knows better.
     */
    public function resolveWorkItem(string $reference): ?string
    {
        $id = WorkItemModel::query()
            // Upper-cased on the way in: references are printed and typed, and
            // "eng-142" is the same item as "ENG-142" to everybody but a
            // database. The index on upper(reference) exists for exactly this.
            ->whereRaw('upper(reference) = ?', [mb_strtoupper(trim($reference))])
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
