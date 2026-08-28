<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service;

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Domain\Exception\IllegalTransition;
use App\Modules\Workflow\Domain\Exception\TransitionRequiresComment;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowStateModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WorkflowTransitionModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Which moves are legal, and why (docs/02 §7).
 *
 * Two responsibilities, and keeping them together is deliberate: the set of
 * transitions the UI renders and the set the API accepts must come from the
 * same query, or the interface will offer buttons that fail.
 */
final class TransitionService
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly PermissionResolver $permissions,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The moves available from a state, for THIS actor, right now.
     *
     * Guards are evaluated here rather than only on write, so a reviewer never
     * sees an Approve button that will 403. Hiding it is a courtesy; the API
     * still refuses independently (docs/07 §4).
     *
     * @param  array<string, mixed>  $subjectFacts
     * @return list<array<string, mixed>>
     */
    public function availableFrom(
        string $workflowId,
        ?string $fromStateId,
        array $subjectFacts = [],
    ): array {
        $transitions = WorkflowTransitionModel::query()
            ->with('toState')
            ->where('workflow_id', $workflowId)
            ->where(fn ($q) => $q
                ->where('from_state_id', $fromStateId)
                // NULL means "from anywhere" — how Cancelled and Blocked are
                // reachable without enumerating every source state.
                ->orWhereNull('from_state_id'))
            ->orderBy('position')
            ->get();

        $available = [];

        foreach ($transitions as $transition) {
            if ($transition->to_state_id === $fromStateId) {
                continue;
            }

            $blocked = $this->guardFailure($transition, $subjectFacts);

            $available[] = [
                'id' => $transition->id,
                'label' => $transition->label,
                'to_state' => [
                    'id' => $transition->toState?->id,
                    'key' => $transition->toState?->key,
                    'label' => $transition->toState?->label,
                    'category' => $transition->toState?->category,
                    'color' => $transition->toState?->color,
                ],
                'requires_comment' => $transition->requires_comment,
                /*
                 * A transition with no from-state is reachable from ANYWHERE —
                 * Blocked, Cancelled, and the like. That makes it an escape
                 * hatch rather than a step forward, and the distinction matters
                 * to the client: the UI picked "Mark blocked" as its suggested
                 * next action for a reviewer who could not approve, which is
                 * technically the first available move and terrible advice.
                 *
                 * Exposed as a flag rather than as the raw from_state_id, so the
                 * client does not have to infer intent from a null.
                 */
                'is_escape_hatch' => $transition->from_state_id === null,
                // Returned rather than filtered out: a disabled control with a
                // reason teaches the workflow, a missing one reads as a bug.
                'available' => $blocked === null,
                'blocked_reason' => $blocked,
            ];
        }

        return $available;
    }

    /**
     * Assert that a move is legal, and return the transition that authorises it.
     *
     * Called by WorkItemService inside the write transaction — so the check and
     * the write cannot be separated by a concurrent workflow edit.
     *
     * @param  array<string, mixed>  $subjectFacts
     */
    public function assertLegal(
        string $workflowId,
        ?string $fromStateId,
        string $toStateId,
        array $subjectFacts = [],
        ?string $comment = null,
    ): WorkflowTransitionModel {
        /** @var WorkflowTransitionModel|null $transition */
        $transition = WorkflowTransitionModel::query()
            ->where('workflow_id', $workflowId)
            ->where('to_state_id', $toStateId)
            ->where(fn ($q) => $q->where('from_state_id', $fromStateId)->orWhereNull('from_state_id'))
            // A specific from-state beats a wildcard, so a targeted guard is
            // not bypassed by a permissive "from anywhere" rule.
            ->orderByRaw('from_state_id IS NULL')
            ->first();

        if ($transition === null) {
            throw new IllegalTransition(
                'That is not a legal move for this workflow.',
                [
                    'from_state_id' => $fromStateId,
                    'to_state_id' => $toStateId,
                    'allowed' => array_column(
                        array_filter(
                            $this->availableFrom($workflowId, $fromStateId, $subjectFacts),
                            static fn (array $t): bool => $t['available'],
                        ),
                        'label',
                    ),
                ],
            );
        }

        /*
         * A missing PERMISSION is a 403; a move the graph forbids is a 409.
         *
         * Both used to come back as 409 "illegal transition", which tells the
         * caller the workflow has no such edge when in fact the edge exists and
         * they are not allowed to take it. The role guard is still reported
         * first — see guardFailure() for why that order is deliberate.
         */
        if ($this->roleGuardPasses($transition, $subjectFacts)
            && ! $this->permissionGuardPasses($transition)) {
            throw new AuthorizationException(
                "You do not have permission to \"{$transition->label}\".",
            );
        }

        $blocked = $this->guardFailure($transition, $subjectFacts);

        if ($blocked !== null) {
            throw new IllegalTransition($blocked, [
                'transition' => $transition->label,
                // The roles the actor actually holds on this item. "Only the
                // reviewer can do this" is unanswerable without it: the person
                // cannot tell whether they are missing the role or the system
                // failed to see it (docs/05 §3).
                'your_roles' => array_values((array) ($subjectFacts['actor_roles'] ?? [])),
            ]);
        }

        if ($transition->requires_comment && trim((string) $comment) === '') {
            throw new TransitionRequiresComment(
                "\"{$transition->label}\" requires a comment explaining the decision.",
                ['transition' => $transition->label],
            );
        }

        return $transition;
    }

    /**
     * Record the move. Append-only, and the row is the evidence cycle-time
     * reporting is computed from (docs/03 §4).
     *
     * @return string the causation id for anything this move triggers
     */
    public function recordTransition(
        string $workItemId,
        ?string $fromStateId,
        string $toStateId,
        ?string $fromCategory,
        string $toCategory,
        string $cause = 'user',
        ?string $causationId = null,
        int $causationDepth = 0,
        ?string $overrideReason = null,
    ): string {
        $id = (string) new UuidV7;

        DB::table('work_item_transitions')->insert([
            'id' => $id,
            'organization_id' => $this->tenant->organizationId(),
            'work_item_id' => $workItemId,
            'from_state_id' => $fromStateId,
            'to_state_id' => $toStateId,
            'from_category' => $fromCategory,
            'to_category' => $toCategory,
            // hasMembership(), not hasTenant(): a rule-caused move runs in a job
            // that has an organization and no membership, and membershipId()
            // throws there. cause === 'user' already covers the normal case; the
            // second check is about the context, not the cause.
            'actor_membership_id' => $cause === 'user' && $this->tenant->hasMembership()
                ? $this->tenant->membershipId()
                : null,
            'cause' => $cause,
            'causation_id' => $causationId ?? $id,
            'causation_depth' => $causationDepth,
            'override_reason' => $overrideReason,
            'occurred_at' => now(),
        ]);

        return $causationId ?? $id;
    }

    /**
     * Does the actor hold one of the roles this transition names?
     *
     * @param  array<string, mixed>  $facts
     */
    private function roleGuardPasses(WorkflowTransitionModel $transition, array $facts): bool
    {
        $guard = (array) ($transition->guard ?? []);

        if (! isset($guard['actor_is'])) {
            return true;
        }

        return array_intersect(
            (array) $guard['actor_is'],
            (array) ($facts['actor_roles'] ?? []),
        ) !== [];
    }

    private function permissionGuardPasses(WorkflowTransitionModel $transition): bool
    {
        $guard = (array) ($transition->guard ?? []);

        if (! isset($guard['permission'])) {
            return true;
        }

        $actor = MembershipModel::query()->find($this->tenant->membershipId());

        return $actor !== null && $this->permissions->has($actor, (string) $guard['permission']);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return string|null the reason the guard blocks, or null if it passes
     */
    private function guardFailure(WorkflowTransitionModel $transition, array $facts): ?string
    {
        $guard = (array) ($transition->guard ?? []);

        if ($guard === []) {
            return null;
        }

        /*
         * Role first, permission second — and the order is the whole point.
         *
         * Both guards can fail at once for the same person: an employee who is
         * the assignee on an item in review holds neither approval.decide nor
         * the reviewer role. Reporting the missing PERMISSION tells them
         * something about the RBAC configuration, which they cannot act on and
         * did not ask about. Reporting the missing ROLE tells them how the
         * workflow works: this is the reviewer's move, not yours.
         */
        if (isset($guard['actor_is'])) {
            $required = (array) $guard['actor_is'];
            $actorRoles = (array) ($facts['actor_roles'] ?? []);

            if (array_intersect($required, $actorRoles) === []) {
                $names = implode(' or ', $required);

                return "Only the {$names} can \"{$transition->label}\".";
            }
        }

        // A permission guard is checked against the actor, not the subject.
        if (isset($guard['permission'])) {
            $actor = MembershipModel::query()->find($this->tenant->membershipId());

            if ($actor === null || ! $this->permissions->has($actor, (string) $guard['permission'])) {
                return "You do not have permission to \"{$transition->label}\".";
            }
        }

        if (isset($guard['condition'])
            && ! $this->conditions->matches((array) $guard['condition'], $facts)) {
            return (string) ($guard['message'] ?? "\"{$transition->label}\" is not available yet.");
        }

        return null;
    }

    /**
     * The seven fixed categories, for a UI that must render unknown states.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return WorkflowStateModel::CATEGORIES;
    }
}
