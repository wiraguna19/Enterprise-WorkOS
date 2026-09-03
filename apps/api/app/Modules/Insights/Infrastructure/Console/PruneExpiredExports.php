<?php

declare(strict_types=1);

namespace App\Modules\Insights\Infrastructure\Console;

use App\Modules\Files\Domain\Contract\FileStorage;
use App\Modules\Insights\Infrastructure\Eloquent\ReportExportModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Deletes expired export files, and says so on the row (ADR 0011).
 *
 * Shipped WITH the expiry column rather than after it. A column that records
 * when something expires, with nothing acting on it, is a column that lies —
 * and this codebase's most expensive recurring defect is precisely the thing
 * that was declared and never built.
 *
 * The object goes and the row stays, marked `expired`. "Where did my file go"
 * is asked after the fact, and the row is the only thing that can answer it.
 */
final class PruneExpiredExports extends Command
{
    protected $signature = 'insights:prune-expired-exports';

    protected $description = 'Delete the files behind expired report exports';

    public function handle(TenantContext $tenant, FileStorage $storage): int
    {
        // Across every tenant: this is maintenance over a bucket, not a read on
        // anyone's behalf. Platform mode is the one legal way to leave a
        // tenant's rows, it is logged, and its call sites are asserted.
        $deleted = $tenant->runAsPlatform('prune expired report exports', function () use ($storage): int {
            $count = 0;

            ReportExportModel::query()
                ->where('status', 'ready')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->each(function (ReportExportModel $export) use ($storage, &$count): void {
                    if ($export->storage_path !== null) {
                        $storage->delete($export->storage_path);
                    }

                    $export->forceFill([
                        'status' => 'expired',
                        'storage_path' => null,
                    ])->save();

                    $count++;
                });

            return $count;
        });

        $this->info("Expired {$deleted} report exports.");

        return self::SUCCESS;
    }
}
