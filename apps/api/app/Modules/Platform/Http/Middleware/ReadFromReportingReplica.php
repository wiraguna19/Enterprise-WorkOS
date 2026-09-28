<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Infrastructure\Database\ReportingReplica;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve this route's reads from the reporting replica, when there is one
 * (ADR 0053).
 *
 * Safe methods only. A POST under this middleware would run its write against
 * a replica and fail — so rather than trusting every route it is attached to
 * to be a read, it checks, and anything else passes through to the primary.
 */
final class ReadFromReportingReplica
{
    public function __construct(
        private readonly ReportingReplica $replica,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        return $this->replica->run(fn (): Response => $next($request));
    }
}
