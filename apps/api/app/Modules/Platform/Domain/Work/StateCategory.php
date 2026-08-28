<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Work;

/**
 * The seven fixed buckets every workflow state maps to (docs/02 §7).
 *
 * In Platform, not in Workflow, because this is shared vocabulary rather than
 * one module's private property. "Is this work finished" is asked by lists,
 * boards, search, the calendar and the workload calculation — none of which
 * have any other business knowing that a workflow engine exists.
 *
 * Keeping it in Workflow is what made `Work → Workflow` an import, and so made
 * the module graph a cycle: Workflow already reaches into Work, as it must, to
 * act on the events Work emits (ADR 0002).
 *
 * Custom states are per organization; these buckets are not. A state's LABEL is
 * whatever a customer calls it, and its CATEGORY is what every query in the
 * product reasons about — which is exactly why the category list is closed and
 * the label list is not.
 */
final class StateCategory
{
    public const ALL = [
        'backlog', 'todo', 'in_progress', 'in_review', 'blocked', 'done', 'cancelled',
    ];

    /** "This work is finished, stop counting it." */
    public const CLOSED = ['done', 'cancelled'];

    /** Categories that consume capacity in the workload calculation (docs/02 §11). */
    public const COMMITTED = ['todo', 'in_progress', 'in_review'];

    public static function isClosed(?string $category): bool
    {
        return in_array($category, self::CLOSED, strict: true);
    }
}
