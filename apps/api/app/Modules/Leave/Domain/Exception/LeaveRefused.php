<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal in leave, named by its `refusal` detail (ADR 0063).
 */
final class LeaveRefused extends DomainException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, array $details = [], private readonly int $status = 409)
    {
        parent::__construct($message, $details);
    }

    public function errorCode(): string
    {
        return 'leave.refused';
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
