<?php

declare(strict_types=1);

namespace App\Modules\Approval\Providers;

use App\Modules\Approval\Http\Policy\ApprovalPolicy;
use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApprovalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(ApprovalModel::class, ApprovalPolicy::class);

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
