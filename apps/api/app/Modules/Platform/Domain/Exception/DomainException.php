<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exception;

/**
 * Base for every rule violation the domain expresses.
 *
 * Carries a stable machine-readable code (clients branch on this, never on the
 * message — see docs/05-api-architecture.md §3), an HTTP status, and structured
 * details. The exception renderer turns it into the standard error envelope.
 */
abstract class DomainException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
