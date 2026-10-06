<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Identity\Http\Middleware\ResolveTenant;
use App\Modules\Identity\Infrastructure\Console\PruneExpiredSessions;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Saml\SamlToolkit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
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

        // The service provider's URLs are the WEB application's — the browser
        // posts the IdP's answer there, and the session cookie is set there
        // (ADR 0052). Laravel's `app.frontend_url` is FRONTEND_URL, which the
        // invitation links already use.
        $this->app->singleton(
            SamlToolkit::class,
            fn (): SamlToolkit => new SamlToolkit((string) config('app.frontend_url', 'http://localhost:3000')),
        );
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

        // A migration may change what every role grants — the permission
        // seeders do exactly that, straight into `role_permissions` — and the
        // per-membership cache versions cannot see it. Without this, a newly
        // granted permission stayed invisible for up to fifteen minutes to
        // anybody whose set was already cached (ADR 0049).
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(PermissionResolver::class)->invalidateEverything();
        });
    }

    /** Whose credentials a login-limited request is trying. */
    private static function loginSubject(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        if ($email !== '') {
            return 'email:'.$email;
        }

        $user = $request->user();

        if ($user !== null) {
            return 'user:'.(string) $user->getAuthIdentifier();
        }

        $challenge = (string) $request->input('challenge');

        return $challenge === '' ? 'anonymous' : 'challenge:'.hash('sha256', $challenge);
    }

    /**
     * Login is limited per (IP + email) rather than per IP alone: per-IP only
     * lets one attacker lock every user out of a shared office network, and
     * per-email only lets a botnet spread a spray across addresses.
     */
    private function registerRateLimiters(): void
    {
        /*
         * Three limits, because three different attacks reach this:
         *
         *   one address guessing one account   — 5 per quarter hour
         *   many addresses guessing one account — 20 (rotating IPs gains nothing past it)
         *   one address trying many accounts    — 50
         *
         * WHO is the email when there is one, and otherwise what the request
         * carries instead: the signed-in user (re-authentication, confirming a
         * factor) or the MFA challenge. Keying those on an empty email put every
         * MFA prompt in the product into one shared bucket.
         *
         * Every key is prefixed so the three never collide. The address is the
         * real client's (TrustTheWebServer); before that it was the web
         * server's, and the per-address limit was one limit for everybody.
         */
        RateLimiter::for('login', function (Request $request): array {
            $ip = (string) $request->ip();
            $who = self::loginSubject($request);

            return [
                Limit::perMinutes(15, 5)->by("login:ip-who:{$ip}|{$who}"),
                Limit::perMinutes(15, 20)->by("login:who:{$who}"),
                Limit::perMinutes(15, 50)->by("login:ip:{$ip}"),
            ];
        });

        /*
         * Accepting an invitation is reachable with nothing but a link, so it
         * is throttled like login rather than like a write — but on its own
         * limiter, because login's key is `ip|email` and these requests carry
         * no email, which would collapse every caller behind one NAT onto the
         * same five attempts per quarter hour.
         *
         * Keyed by the token as well as the address: guessing tokens from one
         * IP is what this is for, and somebody retyping their own link is not.
         */
        RateLimiter::for('invitation', fn (Request $request) => [
            Limit::perMinutes(15, 10)->by('invitation:ip-token:'.$request->ip().'|'.$request->route('token')),
            // Per link whatever the address: accepting for an existing account
            // takes that account's password, and a link must not become a
            // password-guessing endpoint by rotating addresses.
            Limit::perMinutes(15, 10)->by('invitation:token:'.$request->route('token')),
            Limit::perMinutes(15, 30)->by('invitation:ip:'.$request->ip()),
        ]);

        /*
         * The IdP's answer and the step after it carry no email to key on,
         * like an invitation. Each pending round trip is single-use, so these
         * guard against a flood rather than a guess — the random values being
         * guessed are 48 characters long.
         */
        RateLimiter::for('sso', fn (Request $request) => Limit::perMinutes(15, 60)->by((string) $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
