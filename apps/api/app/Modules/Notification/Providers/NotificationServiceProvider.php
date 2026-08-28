<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use App\Modules\Notification\Infrastructure\Listener\WorkNotificationSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The only wiring between the domain and notifications. Work and
        // Approval emit events; this subscribes (docs/01 §5).
        Event::subscribe(WorkNotificationSubscriber::class);

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
