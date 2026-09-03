<?php

declare(strict_types=1);

namespace App\Modules\Insights\Http\Controller;

use App\Modules\Files\Domain\Contract\FileStorage;
use App\Modules\Insights\Application\Report\ReportRegistry;
use App\Modules\Insights\Domain\Exception\ExportNotReady;
use App\Modules\Insights\Domain\Exception\ExportRateLimited;
use App\Modules\Insights\Domain\Exception\UnsupportedExportFormat;
use App\Modules\Insights\Infrastructure\Eloquent\ReportExportModel;
use App\Modules\Insights\Infrastructure\Job\BuildReportExport;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Uid\UuidV7;

/**
 * The four reports, and their exports (docs/05, ADR 0011).
 *
 * `show` renders a report inline; `export` records a request and returns 202.
 * A queued job cannot stream bytes to a browser, and building the file
 * synchronously "just for small ones" gives the customer with the most data the
 * worst behaviour — the timeout is not a size problem, it is a promise problem.
 */
final class ReportController extends ApiController
{
    /** Refused rather than faked: no writer is installed (ADR 0011). */
    private const SUPPORTED_FORMATS = ['csv'];

    /** docs/05 §6: five per hour per organization. */
    private const EXPORTS_PER_HOUR = 5;

    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly TenantContext $tenant,
        private readonly FileStorage $storage,
    ) {}

    public function show(Request $request, string $key): ApiResponse
    {
        $builder = $this->reports->get($this->key($key));
        $built = $builder->build($request->query());

        return ApiResponse::collection($built['rows'], [
            'report' => $key,
            'columns' => $builder->columns(),
            'hidden_count' => $built['hidden_count'],
        ]);
    }

    public function export(Request $request, string $key): ApiResponse
    {
        $key = $this->key($key);

        $this->withinRateLimit();

        $validated = $request->validate([
            'format' => ['sometimes', 'string'],
        ]);

        $format = mb_strtolower((string) ($validated['format'] ?? 'csv'));

        if (! in_array($format, self::SUPPORTED_FORMATS, strict: true)) {
            // Named, and not silently downgraded to CSV. An .xlsx that is
            // really a CSV opens, looks right, and lies about what it is.
            throw new UnsupportedExportFormat(
                "Exports are CSV for now; {$format} needs a writer that is not installed yet.",
                ['supported' => self::SUPPORTED_FORMATS],
            );
        }

        $export = new ReportExportModel;

        $export->forceFill([
            'id' => (string) new UuidV7,
            'organization_id' => $this->tenant->organizationId(),
            // Whose eyes the worker will use. Not decoration: this is what the
            // job binds, and therefore what the file will contain.
            'requested_by_membership_id' => $this->tenant->membershipId(),
            'report_key' => $key,
            'format' => $format,
            // Stored verbatim so a file can be explained months later without
            // guessing which filters produced it.
            'parameters' => $request->except(['format']),
            'status' => 'pending',
        ])->save();

        BuildReportExport::dispatch(
            (string) $this->tenant->organizationId(),
            (string) $this->tenant->membershipId(),
            (string) $export->getKey(),
        );

        // Re-read rather than present the instance we just wrote. On a real
        // queue the job has not run and the row still says `pending`; on the
        // `sync` connection it has already finished and the row says `ready`.
        // The in-memory copy says `pending` in BOTH cases, which is a lie in
        // one of them — and it is the case the test suite runs under, so a
        // stale payload here would ship untested.
        $export->refresh();

        return ApiResponse::item($this->present($export), 202);
    }

    /** A person's own exports, newest first. */
    public function index(): ApiResponse
    {
        $exports = ReportExportModel::query()
            ->where('requested_by_membership_id', $this->tenant->membershipId())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ApiResponse::collection(
            $exports->map(fn (ReportExportModel $export): array => $this->present($export))->all()
        );
    }

    /**
     * A short-lived URL for one export.
     *
     * Only the requester's own: the file was built with THEIR visibility, so
     * handing it to a colleague hands over rows that colleague may not be able
     * to see anywhere else in the product.
     */
    public function download(string $id): ApiResponse
    {
        $export = ReportExportModel::query()
            ->where('requested_by_membership_id', $this->tenant->membershipId())
            ->findOrFail($id);

        if (! $export->isDownloadable()) {
            throw new ExportNotReady(match ($export->status) {
                'pending' => 'This export is still being built.',
                'failed' => 'This export failed: '.(string) $export->failure_reason,
                default => 'This export has expired and its file has been deleted.',
            }, ['status' => $export->status]);
        }

        return ApiResponse::item([
            'url' => $this->storage->presignedDownloadUrl(
                (string) $export->storage_path,
                (string) $export->filename,
            ),
            'expires_in_seconds' => 300,
        ]);
    }

    /**
     * Five per hour per ORGANIZATION, counted here rather than by the
     * `throttle:` middleware.
     *
     * Not a style choice: ThrottleRequests sits high in the framework's
     * middleware priority list and runs BEFORE this product's tenant resolver,
     * so a limiter keyed on the organization has nothing to key on and throws.
     * Keying it on the IP instead would have looked correct and enforced a
     * different rule than the one docs/05 §6 specifies — per person, and per
     * office router at that.
     *
     * The organization is the unit because the cost being limited is the
     * worker's: ten people politely exporting twice is the same load as one
     * person exporting twenty times.
     */
    private function withinRateLimit(): void
    {
        $key = 'report-exports:'.$this->tenant->organizationId();

        if (RateLimiter::tooManyAttempts($key, self::EXPORTS_PER_HOUR)) {
            throw new ExportRateLimited(
                'This organization has reached its export limit for the hour.',
                ['retry_after_seconds' => RateLimiter::availableIn($key)],
            );
        }

        RateLimiter::hit($key, 3600);
    }

    /** @return array<string, mixed> */
    private function present(ReportExportModel $export): array
    {
        return [
            'id' => (string) $export->getKey(),
            'report' => $export->report_key,
            'format' => $export->format,
            'status' => $export->status,
            'parameters' => $export->parameters,
            'filename' => $export->filename,
            'byte_size' => $export->byte_size,
            'row_count' => $export->row_count,
            // What this reader was not shown, on the row as well as in the
            // file: a shortfall with no explanation is the defect every
            // drill-through in this phase avoids.
            'hidden_count' => $export->hidden_count,
            'failure_reason' => $export->failure_reason,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'created_at' => $export->created_at->toIso8601String(),
        ];
    }

    private function key(string $key): string
    {
        abort_unless(in_array($key, $this->reports->keys(), strict: true), 404);

        return $key;
    }
}
