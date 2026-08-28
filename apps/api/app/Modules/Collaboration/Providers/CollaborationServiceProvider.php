<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CollaborationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
