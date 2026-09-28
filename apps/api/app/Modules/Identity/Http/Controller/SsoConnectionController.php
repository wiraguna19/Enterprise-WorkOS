<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controller;

use App\Modules\Identity\Application\Service\RecentAuthentication;
use App\Modules\Identity\Application\Service\SsoConnections;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\SsoConnectionModel;
use App\Modules\Identity\Infrastructure\Eloquent\SsoDomainModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Identity\Infrastructure\Saml\SamlToolkit;
use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use Illuminate\Http\Request;

/**
 * Administering the organization's identity provider (ADR 0052).
 *
 * Every write asks for the password again (ADR 0034): which third party may
 * vouch for everybody here — and whether passwords still work — is the
 * setting most worth confirming, and exactly the one somebody would change
 * from a borrowed screen. It also keeps API tokens out, since a token can
 * never re-authenticate (ADR 0049).
 */
final class SsoConnectionController extends ApiController
{
    public function __construct(
        private readonly SsoConnections $connections,
        private readonly SamlToolkit $saml,
        private readonly RecentAuthentication $recent,
    ) {}

    public function show(Request $request): ApiResponse
    {
        $connection = $this->connections->current();

        return $this->ok([
            'connection' => $connection === null ? null : $this->present($connection),
            // What the IdP's administrator needs from this side, shown whether
            // or not a connection exists: it is the first thing they ask for.
            'service_provider' => $this->saml->serviceProvider(),
            // What requiring SSO would end, named before the button is
            // pressed — a setting and its consequence (ADR 0028).
            'password_sessions' => $this->connections->passwordSessionsExcept($this->session($request)),
        ]);
    }

    public function save(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'idp_entity_id' => ['required', 'string', 'max:500'],
            'idp_sso_url' => ['required', 'string', 'url', 'starts_with:https://', 'max:2000'],
            'idp_certificate' => ['required', 'string', 'max:20000'],
            'domains' => ['required', 'array', 'min:1', 'max:20'],
            'domains.*' => ['string', 'max:253'],
        ]);

        $this->recent->require($request);

        /** @var list<string> $domains */
        $domains = array_values(array_map('strval', (array) $validated['domains']));

        $connection = $this->connections->save(
            (string) $validated['idp_entity_id'],
            (string) $validated['idp_sso_url'],
            (string) $validated['idp_certificate'],
            $domains,
            $request,
        );

        return $this->ok($this->present($connection));
    }

    public function destroy(Request $request): ApiResponse
    {
        $this->recent->require($request);

        $this->connections->delete($request);

        return $this->noContent();
    }

    public function enforcement(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'enforced' => ['required', 'boolean'],
        ]);

        $this->recent->require($request);

        $ended = $this->connections->setEnforced(
            (bool) $validated['enforced'],
            $this->session($request),
            $request,
        );

        return $this->ok([
            'enforced' => (bool) $validated['enforced'],
            'sessions_ended' => $ended,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(SsoConnectionModel $connection): array
    {
        return [
            'id' => $connection->id,
            'idp_entity_id' => $connection->idp_entity_id,
            'idp_sso_url' => $connection->idp_sso_url,
            'idp_certificate' => $connection->idp_certificate,
            'domains' => $connection->domains
                ->map(fn (SsoDomainModel $domain): string => $domain->domain)
                ->sort()
                ->values()
                ->all(),
            'enforced' => $connection->enforced,
            'last_succeeded_at' => $connection->last_succeeded_at?->toIso8601String(),
            'updated_at' => $connection->updated_at->toIso8601String(),
        ];
    }

    private function session(Request $request): ?SessionModel
    {
        /** @var UserModel|null $user */
        $user = $request->user();
        $session = $user?->currentAccessToken();

        return $session instanceof SessionModel ? $session : null;
    }
}
