<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Believe X-Forwarded-For only from the web server (config/proxies.php).
 *
 * With the web server trusted, `$request->ip()` is the client the web server
 * saw — the right-most address in the chain that is not itself trusted — and
 * the rate limiters, the session list and the audit log all see real clients
 * instead of one shared address.
 *
 * Read from config at request time rather than set once at boot, so a cached
 * configuration is honoured like every other setting.
 */
final class TrustTheWebServer
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $trusted */
        $trusted = config('proxies.trusted', []);

        Request::setTrustedProxies($trusted, Request::HEADER_X_FORWARDED_FOR);

        return $next($request);
    }
}
