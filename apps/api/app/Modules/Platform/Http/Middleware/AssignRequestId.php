<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

/**
 * Every request carries an ID, on the response and on every log line it
 * produces. Support asks the user for it; the error envelope returns it.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = 'req_'.(new UuidV7)->toBase58();
        $request->attributes->set('request_id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
