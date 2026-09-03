<?php

declare(strict_types=1);

namespace App\Modules\Insights\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * The file is not there to download: still building, failed, or expired.
 *
 * 409 rather than 404 — the export exists and the caller may see it; what has
 * not happened is the part they asked for.
 */
final class ExportNotReady extends DomainException
{
    public function errorCode(): string
    {
        return 'report.export_not_ready';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
