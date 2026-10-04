<?php

declare(strict_types=1);

namespace App\Modules\Insights\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * A KPI request that is well-formed and still refused (ADR 0062).
 * `details.refusal` names which rule refused.
 */
final class KpiRefused extends DomainException
{
    public function errorCode(): string
    {
        return 'kpi.refused';
    }
}
