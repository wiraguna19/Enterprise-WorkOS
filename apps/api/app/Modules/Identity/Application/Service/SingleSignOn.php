<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\NoActiveMembership;
use App\Modules\Identity\Domain\Exception\SingleSignOnRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\SsoConnectionModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Identity\Infrastructure\Saml\SamlRejected;
use App\Modules\Identity\Infrastructure\Saml\SamlToolkit;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Signing in through an organization's identity provider (ADR 0052).
 *
 * Three steps, because there are three parties and two of them are browsers'
 * idea of the same person:
 *
 *   1. **start** — the address names the organization, the organization names
 *      its IdP, and the browser is sent there with a request id to quote back.
 *      The web server also hands over a random BINDING it has put in a cookie.
 *   2. **consume** — the IdP's signed answer arrives (posted by the browser to
 *      the web server, which passes it here) and is verified against that
 *      organization's certificate and that request id. Nothing is issued yet:
 *      what comes back is a one-minute completion code.
 *   3. **complete** — the web server returns with the code AND the binding from
 *      its cookie, and only then is a session issued.
 *
 * Why not issue the session at step 2: the IdP's answer is posted cross-site,
 * where a `SameSite=Lax` cookie is not sent — so step 2 cannot tell whose
 * browser it is in. An attacker could start a sign-in as THEMSELVES, capture
 * the IdP's answer, and make a victim's browser post it: the victim would be
 * signed in as the attacker and type their work into the attacker's account.
 * Step 3 is a same-site redirect, the binding cookie comes with it, and an
 * answer started in one browser cannot be finished in another.
 *
 * Every pending step is one-use (`Cache::pull`) and short-lived. A response
 * replayed after its request was answered finds nothing to answer.
 */
final class SingleSignOn
{
    /** How long somebody may spend at their IdP — a password, a second factor, a coffee. */
    private const PENDING_SECONDS = 600;

    /** Between the IdP's answer and the redirect that follows it: a heartbeat. */
    private const COMPLETION_SECONDS = 60;

    public function __construct(
        private readonly SamlToolkit $saml,
        private readonly SsoConnections $connections,
        private readonly AuthenticationService $auth,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Where to send this browser.
     *
     * @return array{redirect_url: string}
     */
    public function start(string $email, string $binding): array
    {
        $domain = mb_strtolower(trim((string) Str::afterLast($email, '@')));

        $connection = str_contains($email, '@') && $domain !== ''
            ? $this->connections->forDomain($domain)
            : null;

        if ($connection === null) {
            throw SingleSignOnRefused::notAvailable();
        }

        $relayState = Str::random(48);

        $request = $this->saml->authnRequest($connection->identityProvider(), $relayState);

        Cache::put($this->pendingKey($relayState), [
            'request_id' => $request['request_id'],
            'connection_id' => $connection->id,
            'binding' => hash('sha256', $binding),
        ], self::PENDING_SECONDS);

        return ['redirect_url' => $request['url']];
    }

    /**
     * Verify the IdP's answer and say who it is — as a completion code, not
     * yet a session (see the class docblock for why).
     */
    public function consume(string $samlResponse, string $relayState, Request $request): string
    {
        /** @var array{request_id: string, connection_id: string, binding: string}|null $pending */
        $pending = Cache::pull($this->pendingKey($relayState));

        if (! is_array($pending)) {
            throw SingleSignOnRefused::expired();
        }

        $connection = $this->connections->find($pending['connection_id']);

        if ($connection === null) {
            // Removed while somebody was at their IdP.
            throw SingleSignOnRefused::expired();
        }

        try {
            $assertion = $this->saml->verify($connection->identityProvider(), $samlResponse, $pending['request_id']);
        } catch (SamlRejected $rejected) {
            $this->refuse($connection, $request, 'assertion_rejected', ['detail' => $rejected->getMessage()]);

            throw SingleSignOnRefused::rejected();
        }

        if ($assertion->email === null) {
            $this->refuse($connection, $request, 'no_email', ['name_id' => $assertion->nameId]);

            throw SingleSignOnRefused::rejected();
        }

        // The IdP may only vouch for the domains this organization claimed.
        // Without this, any organization's IdP could assert any address, and
        // the only thing between it and somebody else's account would be
        // whether that person also happened to belong here.
        $domain = (string) Str::afterLast($assertion->email, '@');

        // And only the ones it has PROVED it owns (DNS TXT): an unproven
        // claim is a typed word, not a domain.
        if (! $connection->domains->whereNotNull('verified_at')->contains('domain', $domain)) {
            $this->refuse($connection, $request, 'domain_not_claimed', ['email' => $assertion->email]);

            throw SingleSignOnRefused::rejected();
        }

        $user = UserModel::query()->whereRaw('lower(email) = ?', [$assertion->email])->first();

        $membership = $user !== null && $user->isActive()
            ? $this->membershipOf($user, $connection)
            : null;

        if ($user === null || $membership === null) {
            $this->refuse($connection, $request, 'no_account', ['email' => $assertion->email]);

            throw SingleSignOnRefused::noAccount();
        }

        $code = Str::random(48);

        Cache::put($this->completionKey($code), [
            'user_id' => (string) $user->getKey(),
            'connection_id' => $connection->id,
            'binding' => $pending['binding'],
        ], self::COMPLETION_SECONDS);

        return $code;
    }

    /**
     * The same browser, back with its binding: now it is a session.
     *
     * The membership is read again rather than carried over: a minute is short,
     * but it is exactly the minute in which an offboarding lands, and the
     * person being offboarded is who this check exists for.
     *
     * @return array{mfa_required: false, token: string, session: SessionModel, user: UserModel, membership: MembershipModel}
     */
    public function complete(string $code, string $binding, Request $request): array
    {
        /** @var array{user_id: string, connection_id: string, binding: string}|null $completion */
        $completion = Cache::pull($this->completionKey($code));

        if (! is_array($completion)) {
            throw SingleSignOnRefused::expired();
        }

        $connection = $this->connections->find($completion['connection_id']);

        if ($connection === null) {
            throw SingleSignOnRefused::expired();
        }

        if (! hash_equals($completion['binding'], hash('sha256', $binding))) {
            $this->refuse($connection, $request, 'different_browser', []);

            throw SingleSignOnRefused::rejected();
        }

        $user = UserModel::query()->find($completion['user_id']);
        $membership = $user !== null && $user->isActive() ? $this->membershipOf($user, $connection) : null;

        if ($user === null || $membership === null) {
            throw SingleSignOnRefused::noAccount();
        }

        $result = $this->auth->issueSession($user, $membership, $request, authenticatedBy: 'sso');

        $this->connections->recordSuccess($connection);

        return $result;
    }

    /** What an IdP administrator imports. */
    public function metadata(): string
    {
        return $this->saml->metadata();
    }

    private function membershipOf(UserModel $user, SsoConnectionModel $connection): ?MembershipModel
    {
        try {
            return $this->auth->membershipIn($user, $connection->organization_id);
        } catch (NoActiveMembership) {
            return null;
        }
    }

    /**
     * Write down why, where the administrator will look.
     *
     * In the ORGANIZATION's audit log — the person reading it is the one who
     * can fix a certificate — and with the toolkit's own words, which the
     * person signing in is never shown.
     *
     * @param  array<string, mixed>  $details
     */
    private function refuse(SsoConnectionModel $connection, Request $request, string $reason, array $details): void
    {
        $this->tenant->runFor(
            $connection->organization_id,
            fn () => $this->audit->record('auth.sso_failed', ['reason' => $reason] + $details, $request),
        );
    }

    private function pendingKey(string $relayState): string
    {
        return 'sso:pending:'.hash('sha256', $relayState);
    }

    private function completionKey(string $code): string
    {
        return 'sso:completion:'.hash('sha256', $code);
    }
}
