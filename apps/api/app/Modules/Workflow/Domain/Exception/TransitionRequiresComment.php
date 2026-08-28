<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Some moves must be explained — "request changes" above all. Bouncing work
 * back without a reason just sends it around the loop again (docs/08 §4).
 */
final class TransitionRequiresComment extends DomainException
{
    public function errorCode(): string
    {
        return 'workflow.comment_required';
    }
}
