<?php

declare(strict_types=1);

namespace App\Modules\Insights\Providers;

use App\Modules\Insights\Infrastructure\Console\PruneExpiredExports;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class InsightsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredExports::class]);
        }

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
