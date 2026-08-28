<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request — from the session, never from the client.
 *
 * The organization is read off the session row that the bearer token resolved
 * to. There is deliberately no `X-Organization-Id` header and no
 * `?organization_id=` parameter anywhere in the API: if the client could name
 * the tenant, the client could name someone else's tenant.
 *
 * It lives in Identity, not Platform: the tenant is read off the SESSION, and
 * sessions and memberships are Identity's. The kernel may not know either, and
 * a middleware that has to import both was never a Platform concern (docs/04 §3).
 *
 * See docs/06-auth-and-authorization.md §1.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ActingMembership $acting,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var UserModel|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        /** @var SessionModel $session */
        $session = $user->currentAccessToken();
        $organizationId = $session->organization_id ?? null;

        if ($organizationId === null) {
            return response()->json([
                'error' => [
                    'code' => 'auth.no_active_organization',
                    'message' => 'This session is not bound to an organization.',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        // Membership is re-checked on every request rather than trusted from the
        // token: a revoked membership must lose access immediately, not at token
        // expiry (docs/06 §1 — this is why sessions are opaque, not JWTs).
        $membership = MembershipModel::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            return response()->json([
                'error' => [
                    'code' => 'auth.membership_revoked',
                    'message' => 'Your access to this organization has ended.',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        $this->acting->prime($membership);

        $this->context->setFromSession(
            organizationId: (string) $organizationId,
            membershipId: (string) $membership->getKey(),
            userId: (string) $user->getKey(),
        );

        Log::withContext([
            'organization_id' => $organizationId,
            'user_id' => $user->getKey(),
        ]);

        return $next($request);
    }
}
