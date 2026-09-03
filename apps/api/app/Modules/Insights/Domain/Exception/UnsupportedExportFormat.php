<?php

declare(strict_types=1);

namespace App\Modules\Insights\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A format nothing can write yet (ADR 0011).
 *
 * Refused rather than silently downgraded to CSV: an `.xlsx` that is really a
 * CSV opens, looks right, and is a lie about the file's type that some other
 * program eventually chokes on. The client gets a stable code and a message
 * saying what is missing.
 */
final class UnsupportedExportFormat extends DomainException
{
    public function errorCode(): string
    {
        return 'report.format_unsupported';
    }
}
