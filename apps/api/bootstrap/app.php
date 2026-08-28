<?php

declare(strict_types=1);

use App\Modules\Approval\Providers\ApprovalServiceProvider;
use App\Modules\Calendar\Providers\CalendarServiceProvider;
use App\Modules\Collaboration\Providers\CollaborationServiceProvider;
use App\Modules\Files\Providers\FilesServiceProvider;
use App\Modules\Governance\Providers\GovernanceServiceProvider;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Http\Middleware\ResolveTenant;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Insights\Providers\InsightsServiceProvider;
use App\Modules\Notification\Providers\NotificationServiceProvider;
use App\Modules\Organization\Providers\OrganizationServiceProvider;
use App\Modules\Platform\Domain\Exception\DomainException;
use App\Modules\Platform\Http\ApiExceptionRenderer;
use App\Modules\Platform\Http\Middleware\AssignRequestId;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Search\Providers\SearchServiceProvider;
use App\Modules\Work\Providers\WorkServiceProvider;
use App\Modules\Workflow\Providers\WorkflowServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        PlatformServiceProvider::class,
        IdentityServiceProvider::class,
        OrganizationServiceProvider::class,
        GovernanceServiceProvider::class,
        // Phase 3. Order matters only for route registration precedence;
        // dependencies are resolved by the container, not by this list.
        WorkServiceProvider::class,
        CollaborationServiceProvider::class,
        FilesServiceProvider::class,
        // Phase 4. Workflow listens to Work's events, Approval emits its own,
        // and Notification subscribes to both — none of them call each other
        // directly (docs/01 §5).
        WorkflowServiceProvider::class,
        ApprovalServiceProvider::class,
        NotificationServiceProvider::class,
        // Phase 5. Both read what the modules above own; nothing reads them.
        SearchServiceProvider::class,
        CalendarServiceProvider::class,
        // Phase 6. Reads what every other module owns and computes numbers from
        // it; imported by none of them.
        InsightsServiceProvider::class,
    ])
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/health/live',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters. The request ID must exist before anything can log,
        // and the tenant must be resolved before any tenant-scoped query runs.
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->api(append: [
            ResolveTenant::class,
        ]);

        /*
         * ...but appending is not enough, because middleware PRIORITY, not
         * group order, decides when it actually runs.
         *
         * Left at the end of the group, ResolveTenant runs after
         * SubstituteBindings — so route model binding, which resolves a
         * tenant-scoped model straight from the URL, queries with no tenant
         * context and the scope fails closed. Every route with an implicit
         * binding (`teams/{team}`) answers 500 instead of the 404 that hiding
         * another tenant's record is supposed to produce (docs/05 §3).
         *
         * Authenticate still runs first: the tenant comes from the session, so
         * there is nothing to resolve before the session is known.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        $middleware->alias([
            'permission' => RequirePermission::class,
        ]);

        // This is a stateless JSON API: there is no session cookie to protect
        // and no CSRF surface. The browser-facing cookie lives in the Next.js
        // BFF, which does its own double-submit check (docs/06 §3).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            return app(ApiExceptionRenderer::class)->render($e, $request);
        });

        // Never report an expected domain rule violation as an error — it makes
        // the exception tracker useless within a week.
        $exceptions->dontReport([
            DomainException::class,
        ]);
    })
    ->create();
