<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contract;

/**
 * Something that has to know, the moment it changes, which organization the
 * process is acting for (ADR 0051).
 *
 * `TenantContext` tells it on every change — bound, re-bound for a job,
 * entered or left platform mode, reset — and `null` means "no tenant": nothing
 * bound, or platform mode, where crossing tenants is the point.
 *
 * A contract rather than a direct call so the domain object stays free of the
 * database: the implementation that listens is Postgres Row-Level Security,
 * and the context should not know that a database exists.
 */
interface TenantBoundary
{
    public function enter(?string $organizationId): void;
}
