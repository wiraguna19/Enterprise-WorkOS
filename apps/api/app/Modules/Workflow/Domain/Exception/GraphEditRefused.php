<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * An edit to a live workflow that would strand work, or quietly rewrite history.
 *
 * 409, not 422: the request is well-formed and the actor may well have the
 * permission — the GRAPH is in a state that makes this particular edit
 * destructive. Sending 422 would read as "you typed it wrong", which sends
 * somebody back to check a form that is correct.
 *
 * Every instance carries `details.refusal`, a stable name for WHICH invariant
 * refused, so an interface can say something specific without parsing prose.
 */
final class GraphEditRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'workflow.graph_edit_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
