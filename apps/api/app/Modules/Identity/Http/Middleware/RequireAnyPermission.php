<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Layer 3 again (docs/06 §2), for a route two different roles reach for two
 * different reasons.
 *
 * `permission:` is variadic and ANDs — every name listed must be held — which
 * is right for a route that needs a combination and wrong for one that is
 * reached from two sides. Reading an approval is the second kind: the reviewer
 * opens it to decide, the requester opens it to see what they submitted, and
 * neither holds the other's permission.
 *
 * Gating that route on `approval.decide` alone made the requester's branch of
 * `ApprovalPolicy::view` — which names them explicitly — unreachable: a person
 * could withdraw a submission the API would not let them read. The two layers
 * disagreed and the coarse one won, which is the failure mode this codebase
 * has now met twice (see `WorkItemController::move`).
 *
 * This still only asks "may this role do this KIND of thing at all". WHICH
 * record remains the policy's question, and the policy is stricter than this
 * gate on purpose: holding `approval.request` gets you past here and no further
 * unless the approval is actually yours.
 */
final class RequireAnyPermission
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
    ) {}

    public function handle(Request $request, Closure $next, string ...$accepted): Response
    {
        $membership = $this->acting->get();

        if ($membership === null) {
            abort(403, 'No active membership.');
        }

        foreach ($accepted as $permission) {
            if ($this->permissions->has($membership, $permission)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
