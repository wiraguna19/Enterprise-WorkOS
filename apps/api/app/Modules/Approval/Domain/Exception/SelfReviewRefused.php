<?php

declare(strict_types=1);

namespace App\Modules\Approval\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Reviewing your own submission. Off by default because an approval step that the submitter can clear themselves is not an approval step.
 */
final class SelfReviewRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'approval.self_review_refused';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
