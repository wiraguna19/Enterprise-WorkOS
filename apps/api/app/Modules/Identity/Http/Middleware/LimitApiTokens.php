<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an API token may not do, whatever its author may (ADR 0049).
 *
 * Two refusals, both answered before any route model is resolved and neither
 * costing a query — the token row is already in hand:
 *
 * 1. **Nothing under `auth.*` except `auth.me`, and nothing under
 *    `api_tokens.*`.** Those are the routes that manage credentials: sessions,
 *    the second factor, re-authentication, tokens themselves. A leaked token
 *    that could mint another token would outlive its own revocation; one that
 *    could re-authenticate would open the window every sensitive act is
 *    guarded by. By route NAME, not path — and by PREFIX, so a credential
 *    route added later is refused to tokens until somebody decides otherwise.
 *    Over-refusing is the safe direction here: an integration that needs a
 *    new route says so in a bug report; a token that could reach one says
 *    nothing at all.
 * 2. **A read-only token reads.** Anything but GET or HEAD is refused.
 *
 * Browser sessions pass straight through: the first line asks what kind of
 * row authenticated the request, and a session is not this class's business.
 */
final class LimitApiTokens
{
    /** The one `auth.*` route a token may call: "who am I, and where". */
    private const ALLOWED_AUTH_ROUTES = ['auth.me'];

    /**
     * `service_accounts.` joined with them (ADR 0059): those routes issue
     * tokens and give roles, and a token that could reach them could mint its
     * own successor or promote the account it belongs to.
     *
     * @var list<string>
     */
    private const REFUSED_PREFIXES = ['auth.', 'api_tokens.', 'service_accounts.'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var UserModel|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof SessionModel || ! $token->isApiToken()) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        if (! in_array($route, self::ALLOWED_AUTH_ROUTES, strict: true)) {
            foreach (self::REFUSED_PREFIXES as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return $this->refuse(
                        $request,
                        'auth.interactive_session_required',
                        'An API token cannot manage sign-ins, second factors or tokens. Sign in to do this.',
                    );
                }
            }
        }

        if (! $request->isMethodSafe() && ! $token->canWrite()) {
            return $this->refuse(
                $request,
                'auth.token_read_only',
                'This API token was made read-only. Make a read and write token to change anything.',
            );
        }

        return $next($request);
    }

    private function refuse(Request $request, string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 403);
    }
}
