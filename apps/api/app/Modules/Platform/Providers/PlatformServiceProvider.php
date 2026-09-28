<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Database\PostgresRowLevelSecurity;
use App\Modules\Platform\Infrastructure\Realtime\BroadcastPublisher;
use App\Modules\Platform\Infrastructure\Realtime\NullPublisher;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / per job. Never shared across them.
        //
        // Observed by Row-Level Security from birth (ADR 0051): a fresh context
        // announces "no tenant" as it is attached, so a connection reused by a
        // long-lived worker never starts a job still wearing the last job's
        // organization. A no-op unless `tenancy.row_level_security` is on.
        $this->app->scoped(TenantContext::class, function (): TenantContext {
            $context = new TenantContext;
            $context->observeWith(new PostgresRowLevelSecurity);

            return $context;
        });

        // Real-time is off unless a broadcaster is configured. Binding on the
        // config rather than on the environment means a deployment can run with
        // sockets and its test suite can run without them, using the same code
        // path everywhere else (docs/07 §8).
        $this->app->singleton(
            RealtimePublisher::class,
            fn ($app) => $app['config']->get('broadcasting.default') === 'null'
                ? new NullPublisher
                : new BroadcastPublisher($app->make(BroadcastFactory::class)),
        );
    }

    public function boot(): void
    {
        // Lazy loading is an N+1 waiting to happen. Outside production it throws,
        // so the developer who introduced it fixes it (docs/11 §3).
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Any query slower than the budget in docs/01 §8 is worth seeing.
        DB::whenQueryingForLongerThan(500, function ($connection, $event): void {
            Log::warning('db.slow_query', [
                'sql' => $event->sql,
                'time_ms' => $event->time,
            ]);
        });
    }
}
