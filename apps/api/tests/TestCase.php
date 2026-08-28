<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests assert against the demo seed (database/seeders/sql), not factory
     * output, so RefreshDatabase must seed the freshly migrated test database.
     * Without this every Feature test opens on an empty schema and fails on a
     * missing organization rather than on the behaviour it is checking.
     */
    protected bool $seed = true;

    /**
     * Every test request starts from a clean authentication state.
     *
     * One PHP process serves many requests in a test, which production never
     * does. Sanctum's guard is a RequestGuard, and RequestGuard memoizes the
     * user it resolved: the second request in a test reuses the FIRST request's
     * user and never looks at the bearer token it was given. Every symptom of
     * that is misleading — a reviewer's move refused because the guard still
     * thought it was the assignee, a revoked session still answering /auth/me,
     * an empty dashboard belonging to the wrong person — and none of them
     * points at authentication.
     *
     * The tenant context is cleared for the same reason: it is documented as
     * per-request state, and ResolveTenant rebinds it from the session during
     * the request. Leaving the previous actor's binding in place would make a
     * missing rebind invisible.
     */
    /** @return TestResponse<Response> */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tests must not inherit tenant state from one another; a leaked context
        // would make the isolation suite pass for the wrong reason.
        app(TenantContext::class)->reset();
    }

    /** Log in through the real endpoint and return the bearer token. */
    protected function loginAs(string $email, string $password = 'password'): string
    {
        $token = $this->postJson('/api/v1/auth/login', compact('email', 'password'))
            ->assertOk()
            ->json('data.token');

        $this->bindTenantFrom((string) $token);

        return $token;
    }

    /**
     * Bind the tenant context the way ResolveTenant would, from the session the
     * login just created.
     *
     * In production nothing runs outside a request, so the middleware is the
     * only binder. A test, though, arranges and asserts around its requests —
     * `DepartmentModel::query()` in the test body has no middleware to have run
     * for it, and the organization scope fails closed. Binding here means a
     * test that has logged in acts as that person throughout, which is the
     * mental model the suite is written against; anything crossing tenants
     * still has to say so explicitly through actingWithinTenant().
     */
    protected function bindTenantFrom(string $token): void
    {
        $session = SessionModel::findToken($token);

        if ($session === null) {
            return;
        }

        $context = app(TenantContext::class);
        $organizationId = (string) $session->organization_id;

        $membership = $context->runFor(
            $organizationId,
            fn () => MembershipModel::query()
                ->where('user_id', $session->user_id)
                ->where('status', 'active')
                ->firstOrFail(),
        );

        // A second login inside one test replaces the actor rather than
        // colliding with the first: setFromSession refuses a re-bind.
        $context->reset();

        $context->setFromSession(
            organizationId: $organizationId,
            membershipId: (string) $membership->getKey(),
            userId: (string) $session->user_id,
        );
    }

    /** Authenticate as any active member of the given organization. */
    protected function actingAsMemberOf(string $organizationId): static
    {
        $membership = app(TenantContext::class)->runFor(
            $organizationId,
            fn () => MembershipModel::query()->with('user')->where('status', 'active')->firstOrFail(),
        );

        return $this->withToken($this->loginAs((string) $membership->user->email));
    }
}
