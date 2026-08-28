<?php

declare(strict_types=1);

namespace App\Modules\Search\Providers;

use App\Modules\Search\Domain\Contract\SearchDriver;
use App\Modules\Search\Infrastructure\Driver\PostgresSearchDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place the driver is named. Swapping to Meilisearch is this
        // line and one class (docs/12 §9).
        $this->app->bind(SearchDriver::class, PostgresSearchDriver::class);
    }

    public function boot(): void
    {
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
