<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Identity\Domain\Exception\SingleSignOnRefused;
use App\Modules\Identity\Infrastructure\Eloquent\MembershipModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Identity\Infrastructure\Eloquent\SsoConnectionModel;
use App\Modules\Identity\Infrastructure\Eloquent\SsoDomainModel;
use App\Modules\Identity\Infrastructure\Eloquent\UserModel;
use App\Modules\Identity\Infrastructure\Saml\SamlToolkit;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OneLogin\Saml2\Utils;
use Symfony\Component\Uid\UuidV7;

/**
 * An organization's identity provider: configuring it, and the two questions
 * sign-in asks of it (ADR 0052).
 *
 * Every write goes to the AUDIT log. Which third party may vouch for the
 * people in this organization is the most security-sensitive setting the
 * product has — more than any role — and "who pointed our sign-in at that
 * IdP, with which certificate" is what somebody investigating a breach asks.
 */
final class SsoConnections
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly SamlToolkit $saml,
        private readonly PermissionResolver $permissions,
    ) {}

    public function current(): ?SsoConnectionModel
    {
        return SsoConnectionModel::query()->with('domains')->first();
    }

    /**
     * Create the connection, or replace what it says.
     *
     * Domains are replaced as a set: the form shows the whole list and sends
     * the whole list back, and a PATCH of individual domains would be a second
     * way to arrive at the same list with its own ways to go wrong.
     *
     * `last_succeeded_at` survives a change of certificate or URL. It is the
     * proof that this organization's IdP has worked, not that this exact
     * certificate has — and resetting it would silently switch enforcement off
     * (the CHECK couples them), which is a louder change than the one asked for.
     * A mistake here while enforced is what the break-glass is for.
     *
     * @param  list<string>  $domains
     */
    public function save(
        string $entityId,
        string $ssoUrl,
        string $certificate,
        array $domains,
        ?Request $request = null,
    ): SsoConnectionModel {
        if (! $this->saml->isCertificate($certificate)) {
            throw SingleSignOnRefused::invalidCertificate();
        }

        $domains = $this->normalizeDomains($domains);

        $connection = $this->current() ?? new SsoConnectionModel;
        $creating = ! $connection->exists;

        if ($creating) {
            $connection->id = (string) new UuidV7;
            // Set here as well as defaulted in the table: a model that relied
            // on the column default answers `null` until it is read back,
            // and the response would say "not enforced" as null.
            $connection->enforced = false;
            $connection->created_by_membership_id = $this->tenant->membershipId();
        }

        $connection->idp_entity_id = trim($entityId);
        $connection->idp_sso_url = trim($ssoUrl);
        // Stored as it will be read back: one canonical PEM, whatever
        // whitespace the IdP's console wrapped it in.
        $connection->idp_certificate = (string) Utils::formatCert($certificate, true);

        DB::transaction(function () use ($connection, $domains): void {
            $connection->save();

            SsoDomainModel::query()
                ->where('connection_id', $connection->id)
                ->whereNotIn('domain', $domains)
                ->delete();

            $existing = SsoDomainModel::query()
                ->where('connection_id', $connection->id)
                ->pluck('domain')
                ->all();

            foreach (array_diff($domains, $existing) as $domain) {
                $row = new SsoDomainModel;
                $row->id = (string) new UuidV7;
                $row->connection_id = $connection->id;
                $row->domain = $domain;

                try {
                    // A savepoint per row, so the refusal can name the
                    // domain: under Row-Level Security this organization
                    // cannot SEE the other one's row, and the unique index
                    // is the only thing that can say it exists.
                    DB::transaction(fn (): bool => $row->save());
                } catch (QueryException $e) {
                    if ($e->getCode() === '23505') {
                        throw SingleSignOnRefused::domainTaken($domain);
                    }

                    throw $e;
                }
            }
        });

        $this->audit->record($creating ? 'sso.connection_created' : 'sso.connection_updated', [
            'idp_entity_id' => $connection->idp_entity_id,
            'idp_host' => (string) parse_url($connection->idp_sso_url, PHP_URL_HOST),
            // The fingerprint, not the certificate: enough to tell two apart
            // in a review, short enough to read.
            'certificate_sha256' => $this->fingerprint($connection->idp_certificate),
            'domains' => $domains,
        ], $request, targetType: 'sso_connection', targetId: $connection->id);

        return $connection->load('domains');
    }

    /** Remove it. Refused while it is required — see SingleSignOnRefused::stillEnforced(). */
    public function delete(?Request $request = null): void
    {
        $connection = $this->current() ?? throw SingleSignOnRefused::notConfigured();

        if ($connection->enforced) {
            throw SingleSignOnRefused::stillEnforced();
        }

        $connection->delete();

        $this->audit->record('sso.connection_deleted', [
            'idp_entity_id' => $connection->idp_entity_id,
        ], $request, targetType: 'sso_connection', targetId: $connection->id);
    }

    /**
     * Require single sign-on of everybody here, or stop requiring it.
     *
     * Turning it ON ends every session in this organization that a password
     * opened, except the one asking. A requirement that left thirty days of
     * password sessions running would not be one (ADR 0028's lesson, applied
     * here), and the number is returned so the screen can say what happened.
     * Administrators keep the break-glass: they may sign in with a password
     * again, which is what makes ending their sessions safe.
     *
     * @return int sessions ended
     */
    public function setEnforced(bool $enforced, ?SessionModel $asking, ?Request $request = null): int
    {
        $connection = $this->current() ?? throw SingleSignOnRefused::notConfigured();

        if ($enforced && $connection->last_succeeded_at === null) {
            throw SingleSignOnRefused::untested();
        }

        if ($connection->enforced === $enforced) {
            return 0;
        }

        $connection->enforced = $enforced;
        $connection->save();

        $ended = 0;

        if ($enforced) {
            $query = SessionModel::query()
                ->where('organization_id', $connection->organization_id)
                ->where('kind', 'session')
                ->where('authenticated_by', 'password')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now());

            if ($asking !== null) {
                $query->whereKeyNot($asking->getKey());
            }

            $ended = $query->update(['revoked_at' => now(), 'revoked_reason' => 'sso_required']);
        }

        $this->audit->record($enforced ? 'sso.enforced' : 'sso.enforcement_lifted', [
            'sessions_ended' => $ended,
        ], $request, targetType: 'sso_connection', targetId: $connection->id);

        return $ended;
    }

    /**
     * Password sessions that requiring SSO would end right now, for the screen
     * to say before the button is pressed.
     */
    public function passwordSessionsExcept(?SessionModel $asking): int
    {
        $query = SessionModel::query()
            ->where('organization_id', $this->tenant->organizationId())
            ->where('kind', 'session')
            ->where('authenticated_by', 'password')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        if ($asking !== null) {
            $query->whereKeyNot($asking->getKey());
        }

        return $query->count();
    }

    /**
     * The people requiring single sign-on would lock out.
     *
     * Active members whose address is outside the connection's domains can
     * sign in neither way once passwords are refused: the IdP is never asked
     * about them, and the password form turns them away. Found the first time
     * enforcement was tried by hand — a manager was locked out, and the panel
     * had counted SESSIONS, which is the wrong noun: the consequence lands on
     * people. Named rather than counted, so the administrator can see whether
     * it is a contractor they meant to exclude or half the company.
     *
     * The break-glass holders are left out, because a password still works for
     * them (see AuthenticationService::passwordMayEnter()).
     *
     * @return list<array{membership_id: string, name: string, email: string}>
     */
    public function membersOutsideDomains(SsoConnectionModel $connection): array
    {
        $domains = $connection->domains->pluck('domain')->all();
        $out = [];

        $members = MembershipModel::query()
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->get();

        // One read for every address rather than a relation per row.
        $users = UserModel::query()
            ->whereIn('id', $members->pluck('user_id')->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        foreach ($members as $membership) {
            $user = $users->get($membership->user_id);
            $email = mb_strtolower((string) $user?->email);
            $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : '';

            if (in_array($domain, $domains, strict: true)) {
                continue;
            }

            if ($this->permissions->has($membership, 'sso.manage')) {
                continue;
            }

            $out[] = [
                'membership_id' => (string) $membership->getKey(),
                'name' => (string) $user?->name,
                'email' => $email,
            ];
        }

        usort($out, static fn ($a, $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    // ── What sign-in asks ───────────────────────────────────────────────────

    /**
     * The connection that signs this domain in, whichever organization owns it.
     *
     * Across tenants by definition — sign-in is how the tenant is FOUND — so it
     * says so, through platform mode (ADR 0051), rather than bypassing the
     * scope quietly.
     */
    public function forDomain(string $domain): ?SsoConnectionModel
    {
        return $this->tenant->runAsPlatform(
            'find the identity provider for an email domain',
            function () use ($domain): ?SsoConnectionModel {
                $row = SsoDomainModel::query()->where('domain', mb_strtolower($domain))->first();

                return $row === null
                    ? null
                    : SsoConnectionModel::query()->with('domains')->find($row->connection_id);
            },
        );
    }

    /** One connection by id, across tenants, for the second half of a round trip. */
    public function find(string $connectionId): ?SsoConnectionModel
    {
        return $this->tenant->runAsPlatform(
            'resume a single sign-on round trip',
            fn (): ?SsoConnectionModel => SsoConnectionModel::query()->with('domains')->find($connectionId),
        );
    }

    /**
     * Does this organization refuse passwords?
     *
     * A read of one boolean, asked on every password sign-in, so it is the
     * query builder rather than the model: no scope to satisfy, no platform
     * mode to log, and nothing returned but the answer.
     */
    public function enforcedFor(string $organizationId): bool
    {
        return DB::table('sso_connections')
            ->where('organization_id', $organizationId)
            ->where('enforced', true)
            ->exists();
    }

    /** Somebody got in through it: the proof enforcement waits for. */
    public function recordSuccess(SsoConnectionModel $connection): void
    {
        DB::table('sso_connections')
            ->where('id', $connection->id)
            ->update(['last_succeeded_at' => now()]);
    }

    /**
     * Lower-case, no @, no duplicates, at least one — and each one a domain.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function normalizeDomains(array $domains): array
    {
        $out = [];

        foreach ($domains as $domain) {
            $domain = mb_strtolower(trim(ltrim(trim($domain), '@')));

            if ($domain === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) !== 1) {
                throw SingleSignOnRefused::invalidDomain($domain);
            }

            $out[$domain] = $domain;
        }

        if ($out === []) {
            throw SingleSignOnRefused::noDomains();
        }

        return array_values($out);
    }

    private function fingerprint(string $pem): string
    {
        $der = base64_decode((string) Utils::formatCert($pem, false), true);

        return $der === false ? '' : implode(':', str_split(strtoupper(hash('sha256', $der)), 2));
    }
}
