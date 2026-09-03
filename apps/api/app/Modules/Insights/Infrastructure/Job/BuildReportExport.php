<?php

declare(strict_types=1);

namespace App\Modules\Insights\Infrastructure\Job;

use App\Modules\Files\Domain\Contract\FileStorage;
use App\Modules\Insights\Application\Report\CsvWriter;
use App\Modules\Insights\Application\Report\ReportRegistry;
use App\Modules\Insights\Infrastructure\Eloquent\ReportExportModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds one export, with the REQUESTER'S eyes (ADR 0011).
 *
 * Every other queued job in this product binds `TenantContext::runFor()`, which
 * gives an organization and deliberately no membership: a rule engine acts as
 * the system. **This one must not.** Its entire content is a visibility
 * decision, and a worker with no membership either sees nothing — or, once
 * somebody "fixes" that by widening the query, sees everything.
 *
 * So it binds `runForMembership()`, which `ActingMembership` resolves from, so
 * `WorkItemVisibility` and every policy downstream behave exactly as they did
 * in the request that asked for the file.
 *
 * Permissions are therefore re-resolved AT RUN TIME. Someone whose access
 * narrowed between asking and the job running gets the narrower file, and that
 * is correct: an export is not a promise made at 09:00 and honoured at 09:05.
 *
 * The job carries ids, never the rows — the same rule the realtime events
 * follow. A payload with content in it is a copy of the data sitting in Redis
 * with none of the visibility that produced it.
 */
final class BuildReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt. A failed export is a row a person can see and retry. */
    public int $tries = 1;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $membershipId,
        private readonly string $exportId,
    ) {}

    public function handle(
        TenantContext $tenant,
        ReportRegistry $reports,
        CsvWriter $csv,
        FileStorage $storage,
    ): void {
        $tenant->runForMembership($this->organizationId, $this->membershipId, function () use (
            $reports,
            $csv,
            $storage,
        ): void {
            $export = ReportExportModel::query()->find($this->exportId);

            if ($export === null || $export->status !== 'pending') {
                // Already handled, or gone. A retry that rebuilds a ready file
                // would replace it with a newer one under the same name, which
                // is a different file than the person was told about.
                return;
            }

            try {
                $builder = $reports->get($export->report_key);
                $built = $builder->build($export->parameters);

                $contents = $csv->write($builder->columns(), $built['rows']);

                $filename = sprintf(
                    '%s-report-%s.csv',
                    $export->report_key,
                    now()->format('Y-m-d'),
                );

                // Namespaced by organization AND by requester: the path is not
                // a secret, but a bucket listing should not hand one tenant a
                // map of another's files.
                $path = sprintf(
                    'exports/%s/%s/%s.csv',
                    $this->organizationId,
                    $this->membershipId,
                    $export->getKey(),
                );

                $storage->put($path, $contents, CsvWriter::MIME_TYPE);

                $export->forceFill([
                    'status' => 'ready',
                    'storage_path' => $path,
                    'filename' => $filename,
                    'byte_size' => strlen($contents),
                    'row_count' => count($built['rows']),
                    'hidden_count' => $built['hidden_count'],
                    'completed_at' => now(),
                    // Long enough to find the email and click it, short enough
                    // that a bucket does not become an archive nobody audits.
                    'expires_at' => now()->addDays(7),
                ])->save();
            } catch (Throwable $e) {
                // The reason is stored, not just logged: "my export failed" is
                // asked by the person who requested it, and the row is the only
                // place they can be answered from.
                $export->forceFill([
                    'status' => 'failed',
                    'failure_reason' => mb_substr($e->getMessage(), 0, 200),
                ])->save();

                throw $e;
            }
        });
    }
}
