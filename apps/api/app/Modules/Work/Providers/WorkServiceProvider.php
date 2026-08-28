<?php

declare(strict_types=1);

namespace App\Modules\Work\Providers;

use App\Modules\Work\Http\Policy\ProjectPolicy;
use App\Modules\Work\Http\Policy\WorkItemPolicy;
use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class WorkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not transient: Gate resolves a policy from the container on
        // EVERY authorize() call, and a fresh instance each time turns one
        // cached membership lookup into one query per rendered row — the N+1
        // the query-count tests exist to catch (docs/11 §3). Scoped rather than
        // singleton so the cached actor dies with the request, never outliving
        // a permission change.
        $this->app->scoped(WorkItemPolicy::class);
        $this->app->scoped(ProjectPolicy::class);
    }

    public function boot(): void
    {
        Gate::policy(WorkItemModel::class, WorkItemPolicy::class);
        Gate::policy(ProjectModel::class, ProjectPolicy::class);

        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/../Routes/api.php');
    }
}
