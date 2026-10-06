<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organization that requires a second factor, enforced (ADR 0033).
 *
 * **Confinement, not a locked door.** Somebody without a factor in an
 * organization that requires one still signs in, and the session they get can
 * do exactly four things: say who they are, sign out, start enrolment, and
 * finish it — and, since ADR 0050, leave for another organization they belong
 * to. Everything else answers 403 `auth.mfa_required`.
 *
 * Refusing the sign-in instead would be simpler and wrong in two directions at
 * once. It locks out every person who had no warning, on a switch somebody else
 * flipped — and it locks out the administrator who flipped it, since they have
 * no factor either at that moment. A policy whose first act is to lock its own
 * author out is a policy nobody turns on.
 *
 * Evaluated per request, like the idle timeout (ADR 0029), so turning it on
 * reaches sessions that already exist without ending them. Nobody is signed
 * out; everybody is asked.
 *
 * The organization's answer rides on the session row: `findToken()` already
 * joins `organizations` for the idle window (ADR 0029), so requiring a factor
 * costs no query at all. The client never names the organization — it is read
 * off the session, like everything else about the tenant.
 */
final class RequireSecondFactor
{
    /**
     * The routes a confined session may still reach.
     *
     * By NAME, not by path: a path list drifts the first time somebody moves a
     * route, and it drifts silently, in the direction of letting more through.
     *
     * `auth.mfa.disable` is deliberately absent. Turning the factor off in an
     * organization that requires it would confine the person a millisecond
     * later, which is a loop rather than a feature — `MultiFactor::disable`
     * refuses it outright so the answer says why.
     */
    private const ALLOWED = [
        'auth.me',
        'auth.logout',
        // Beginning enrolment asks for the password again once the sign-in
        // is older than the window (ADR 0034), so proving it must stay open.
        'auth.reauthenticate',
        'auth.mfa.begin',
        'auth.mfa.confirm',
        // Leaving (ADR 0050). The requirement is THIS organization's; somebody
        // who also belongs to one that does not require a factor must be able
        // to go there, or confinement here becomes a lockout everywhere.
        'auth.organizations',
        'auth.organization.switch',
    ];

    /**
     * No dependencies, and that is the point.
     *
     * The first version injected the policy reader and asked the organizations
     * table on every request. Six query budgets failed inside a minute — which
     * is exactly what they exist for — so the answer rides along on the join
     * `SessionModel::findToken()` already makes for the idle window (ADR 0029).
     * Raising the budgets instead would have bought a second read of a row this
     * request had already touched.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var UserModel|null $user */
        $user = $request->user();

        // A service account has no second factor to enrol, because it has no
        // person to hold one (ADR 0059). Its token is the credential, issued by
        // an administrator who had to pass the organization's requirement.
        if ($user === null || $user->hasMfaEnabled() || $user->isService()) {
            return $next($request);
        }

        $session = $user->currentAccessToken();

        if (! $session instanceof SessionModel || ! $session->organizationRequiresSecondFactor()) {
            return $next($request);
        }

        // Signed in through the organization's identity provider: the second
        // factor, if the organization wants one, is the IdP's to demand — and
        // asking again here would make somebody enrol a TOTP app for a
        // password they never use (ADR 0052).
        if ($session->isSingleSignOn()) {
            return $next($request);
        }

        if (in_array((string) $request->route()?->getName(), self::ALLOWED, strict: true)) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'auth.mfa_required',
                // Written for the person, not for the log. Whoever reads this
                // has just had a working product stop working, and the sentence
                // has to say both that nothing is broken and what to do.
                'message' => 'This organization requires two-factor authentication. Set it up to carry on.',
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 403);
    }
}
