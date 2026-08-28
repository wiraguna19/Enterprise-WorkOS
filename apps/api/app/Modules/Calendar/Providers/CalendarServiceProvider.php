<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CalendarServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Per IP, because the feed has no authenticated user to key on. Twelve
        // a minute is far above what any client polls at and far below what
        // guessing a 48-character token would need.
        RateLimiter::for('calendar-feed', fn (Request $request) => Limit::perMinute(12)->by($request->ip()));

        Route::prefix('api/v1')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
