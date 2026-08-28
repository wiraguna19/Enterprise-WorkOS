<?php

declare(strict_types=1);

namespace App\Modules\Governance\Providers;

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Governance\Infrastructure\Console\EnsureLogPartitions;
use Illuminate\Support\ServiceProvider;

final class GovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the correlation ID on ActivityLogger is
        // per-request state and must not leak between queued jobs.
        $this->app->scoped(ActivityLogger::class);
        $this->app->scoped(AuditLogger::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnsureLogPartitions::class]);
        }
    }
}
