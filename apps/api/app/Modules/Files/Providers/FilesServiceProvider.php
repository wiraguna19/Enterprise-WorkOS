<?php

declare(strict_types=1);

namespace App\Modules\Files\Providers;

use App\Modules\Files\Domain\Contract\FileStorage;
use App\Modules\Files\Infrastructure\Storage\S3FileStorage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The whole reason the interface exists: swapping the driver is one
        // binding, not a refactor (docs/03 §5).
        $this->app->bind(FileStorage::class, fn () => new S3FileStorage(
            disk: config('filesystems.default', 's3'),
        ));
    }

    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
