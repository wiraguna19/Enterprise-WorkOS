<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `filter`, `sort` and `include` on an endpoint that answers none of them: a
 * 422, not an ignored parameter (docs/05 §4).
 *
 * ADR 0039 made the four endpoints that DO filter refuse keys they do not
 * know. It left the other half open: every other collection answered
 * `?filter[unread]=1` with the unfiltered list and a 200 — the same silent
 * wrong answer, one level up. A client that believes it asked for unread
 * notifications ships that belief, and nobody notices for a month.
 *
 * So the default is refusal, and a route that answers one of these says so
 * with `answers:filter,sort` (AnswersQuery). The KEYS inside a filter are
 * still that endpoint's business (OnlyKnownFilters); this only asks whether
 * the endpoint takes the parameter at all.
 */
final class RefuseUnansweredQuery
{
    private const GUARDED = ['filter', 'sort', 'include'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $sent = array_values(array_filter(
            self::GUARDED,
            static fn (string $parameter): bool => $request->query->has($parameter),
        ));

        if ($sent === []) {
            return $next($request);
        }

        $answered = self::answeredBy($request->route());
        $refused = array_values(array_diff($sent, $answered));

        if ($refused !== []) {
            throw ValidationException::withMessages(array_combine(
                $refused,
                array_map(
                    static fn (string $parameter): string => $answered === []
                        ? "This endpoint takes no {$parameter}."
                        : "This endpoint takes no {$parameter}. It answers: ".implode(', ', $answered).'.',
                    $refused,
                ),
            ));
        }

        return $next($request);
    }

    /** @return list<string> */
    private static function answeredBy(mixed $route): array
    {
        if (! $route instanceof Route) {
            return [];
        }

        $answered = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'answers:')) {
                array_push($answered, ...explode(',', substr($middleware, strlen('answers:'))));
            }
        }

        return array_values(array_unique($answered));
    }
}
