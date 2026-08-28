<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Http\Middleware\ResolveTenant;
use App\Modules\Identity\Infrastructure\Console\PruneExpiredSessions;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not transient: both memoize per request, and a fresh instance
        // per injection makes that memoization a lie — which is how the same
        // membership row gets read five times in one request (docs/11 §3).
        $this->app->scoped(ActingMembership::class);
        $this->app->scoped(PermissionResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredSessions::class]);
        }

        Sanctum::usePersonalAccessTokenModel(SessionModel::class);

        Route::prefix('api/v1')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');

        // Channel authorization lives here rather than in Platform, and the
        // reason is the module graph: Platform depends on nothing, and this
        // needs the tenant middleware Identity owns. It belongs here anyway —
        // deciding who may listen is the same question as deciding who may
        // read, and Identity is where that question is answered.
        //
        // A socket subscription must be at least as hard to obtain as the HTTP
        // request for the same data, so the auth endpoint runs behind the same
        // authentication AND the same tenant resolution: the callbacks ask
        // visibility questions, which are answered per membership (docs/06 §2).
        Broadcast::routes(['middleware' => ['api', 'auth:sanctum', ResolveTenant::class]]);

        require base_path('routes/channels.php');

        $this->registerRateLimiters();
    }

    /**
     * Login is limited per (IP + email) rather than per IP alone: per-IP only
     * lets one attacker lock every user out of a shared office network, and
     * per-email only lets a botnet spread a spray across addresses.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by(
                $request->ip().'|'.mb_strtolower((string) $request->input('email'))
            ),
            Limit::perMinutes(15, 20)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
