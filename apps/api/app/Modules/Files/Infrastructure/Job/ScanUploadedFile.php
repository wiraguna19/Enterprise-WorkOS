<?php

declare(strict_types=1);

namespace App\Modules\Files\Infrastructure\Job;

use App\Modules\Files\Infrastructure\Eloquent\FileModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Malware scan, on the queue.
 *
 * Idempotent by construction: a file already marked clean or infected is left
 * alone, so a retry after a lost ack cannot flip a verdict (docs/01 §4).
 *
 * Phase 3 ships the job and the quarantine state machine; wiring a real scanner
 * (ClamAV or a hosted API) is a driver swap behind this one class. Until then
 * the verdict is `skipped`, which is honest — and `skipped` files are
 * downloadable while `pending` ones are not, so the state means something.
 */
final class ScanUploadedFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private readonly string $fileId,
    ) {
        $this->onQueue('low');
    }

    public function handle(TenantContext $tenant): void
    {
        $file = $tenant->runAsPlatform(
            'virus scan reads a file before its tenant context is known',
            // No withoutGlobalScopes() here: platform mode already makes the
            // organization scope inert, and a second bypass would be a second
            // way to cross tenants — which is exactly what the isolation suite
            // forbids (docs/01 §6).
            fn () => FileModel::query()->find($this->fileId),
        );

        if ($file === null || $file->scan_status !== 'pending') {
            return;   // already decided, or gone: nothing to do
        }

        $file->forceFill(['scan_status' => 'skipped'])->save();
    }

    /** Deduplicate: one scan per file, however many times it is dispatched. */
    public function uniqueId(): string
    {
        return $this->fileId;
    }
}
