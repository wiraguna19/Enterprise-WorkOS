<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\Service\ActingMembership;
use App\Modules\Identity\Application\Service\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level coarse permission gate: layer 3 of the five in docs/06 §2.
 *
 * It answers "may this role do this KIND of thing at all". It deliberately does
 * NOT answer "may they do it to THIS record" — that is the policy's job, and
 * conflating the two produces middleware that needs the record it does not have.
 */
final class RequirePermission
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly ActingMembership $acting,
    ) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $membership = $this->acting->get();

        if ($membership === null) {
            abort(403, 'No active membership.');
        }

        foreach ($required as $permission) {
            if (! $this->permissions->has($membership, $permission)) {
                abort(403, 'You do not have permission to perform this action.');
            }
        }

        return $next($request);
    }
}
