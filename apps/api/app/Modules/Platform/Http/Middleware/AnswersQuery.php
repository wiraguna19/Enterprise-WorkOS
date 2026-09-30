<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route's declaration that it answers `filter`, `sort` or `include`
 * (`->middleware('answers:filter,sort')`).
 *
 * It does nothing when it runs. It is read by RefuseUnansweredQuery, which is
 * the one that refuses; this is the list it refuses against. A marker rather
 * than a route default, because a default becomes a route PARAMETER and is
 * handed to the controller method along with `{key}` and `{reference}`.
 */
final class AnswersQuery
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$answers): Response
    {
        return $next($request);
    }
}
