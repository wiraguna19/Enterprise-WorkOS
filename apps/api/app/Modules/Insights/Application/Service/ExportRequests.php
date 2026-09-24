<?php

declare(strict_types=1);

namespace App\Modules\Insights\Application\Service;

use App\Modules\Insights\Infrastructure\Eloquent\ReportExportModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Symfony\Component\Uid\UuidV7;

/**
 * Recording that somebody asked for a file (ADR 0046).
 *
 * An export is a row before it is a file. The row is what makes the request
 * answerable while the queue has not reached it — "pending" is a real state a
 * person can be shown, and a request that existed only inside a job would have
 * nothing to show until the job finished.
 */
final class ExportRequests
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function request(string $reportKey, string $format, array $parameters): ReportExportModel
    {
        $export = new ReportExportModel;

        $export->forceFill([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->organizationId(),
            // Whose eyes the worker will use. Not decoration: this is what the
            // job binds, and therefore what the file will contain.
            'requested_by_membership_id' => $this->tenant->membershipId(),
            'report_key' => $reportKey,
            'format' => $format,
            // Stored verbatim so a file can be explained months later without
            // guessing which filters produced it.
            'parameters' => $parameters,
            'status' => 'pending',
        ])->save();

        return $export;
    }
}
