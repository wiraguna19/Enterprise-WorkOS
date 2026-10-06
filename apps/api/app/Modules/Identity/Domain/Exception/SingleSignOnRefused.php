<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal single sign-on can express, each with a name (ADR 0052).
 *
 * Two audiences, two prefixes. `auth.*` is said to somebody signing in, and
 * says as little as it can: why an identity provider's answer was refused is
 * for the administrator, who reads it in the audit log. `sso.*` is said to the
 * administrator configuring the connection, and says exactly what is wrong.
 */
final class SingleSignOnRefused extends DomainException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly string $refusalCode,
        private readonly int $status = 422,
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }

    public function errorCode(): string
    {
        return $this->refusalCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    // ── Signing in ──────────────────────────────────────────────────────────

    /**
     * No organization signs this domain in.
     *
     * Says so rather than pretending to redirect: which domains use single
     * sign-on is not a secret (every product's "continue with SSO" answers the
     * same question), and a person who typed a personal address should learn
     * to use their password, not stare at a spinner.
     */
    public static function notAvailable(): self
    {
        return new self(
            'Single sign-on is not set up for that address. Sign in with your password instead.',
            'auth.sso_not_available',
            422,
        );
    }

    /** The round trip took too long, was used already, or never started here. */
    public static function expired(): self
    {
        return new self(
            'This sign-in has expired. Please start again.',
            'auth.sso_expired',
            401,
        );
    }

    /**
     * The identity provider's answer was refused.
     *
     * One sentence for every reason — a bad signature, a wrong audience, a
     * response for somebody else's request — because each of them is either a
     * misconfiguration only the administrator can fix or an attack, and neither
     * is helped by telling the browser which.
     */
    public static function rejected(): self
    {
        return new self(
            'Your identity provider\'s answer could not be accepted. Try again, and if it keeps happening, tell your administrator.',
            'auth.sso_failed',
            401,
        );
    }

    /**
     * A real person, vouched for by the right IdP — with no account here.
     *
     * Accounts are not created on the fly (ADR 0052): an IdP vouching for an
     * address is not the organization deciding that person should be in it.
     */
    public static function noAccount(): self
    {
        return new self(
            'There is no account here for that address. Ask your administrator for an invitation.',
            'auth.sso_no_account',
            403,
        );
    }

    /** A password, into an organization that has decided passwords are not how you get in. */
    public static function required(): self
    {
        return new self(
            'Your organization signs in through single sign-on. Use "Sign in with single sign-on" instead.',
            'auth.sso_required',
            403,
        );
    }

    /**
     * A session an IdP vouched for, asked to do something only the account's
     * own credentials should: reach another organization, or change the
     * account's second factor.
     */
    public static function sessionBound(string $what): self
    {
        return new self(
            __("You signed in through your organization's identity provider, which can only vouch for you here. Sign in with your password to :what.", ['what' => __($what)]),
            'auth.sso_session_bound',
            403,
        );
    }

    // ── Administering the connection ────────────────────────────────────────

    public static function notConfigured(): self
    {
        return new self(
            'Single sign-on is not set up for this organization.',
            'sso.not_configured',
            404,
        );
    }

    public static function invalidCertificate(): self
    {
        return new self(
            'That is not a certificate. Paste the whole thing, including the BEGIN and END lines, from your identity provider\'s metadata.',
            'sso.invalid_certificate',
            422,
        );
    }

    /**
     * Another organization signs this domain in already.
     *
     * Says which domain and nothing about who: the refusal has to exist (one
     * address, one IdP), and naming the other organization would not.
     */
    public static function domainTaken(string $domain): self
    {
        return new self(
            __(":domain already signs in through another organization's identity provider.", ['domain' => $domain]),
            'sso.domain_taken',
            409,
            ['domain' => $domain],
        );
    }

    public static function domainNotClaimed(string $domain): self
    {
        return new self(
            __(':domain is not one of this connection\'s domains.', ['domain' => $domain]),
            'sso.domain_not_claimed',
            404,
            ['domain' => $domain],
        );
    }

    /**
     * The record is not there, or not yet visible.
     *
     * Says exactly where to put what: the person reading this is about to
     * open a DNS console, and DNS changes can take minutes to be seen.
     */
    public static function domainNotProven(string $domain, string $recordName, string $recordValue): self
    {
        return new self(
            __('No matching TXT record at :name yet. Publish ":value" there, then try again — DNS changes can take a few minutes to be seen.', [
                'name' => $recordName,
                'value' => $recordValue,
            ]),
            'sso.domain_not_verified',
            409,
            ['domain' => $domain, 'record_name' => $recordName, 'record_value' => $recordValue],
        );
    }

    public static function invalidDomain(string $domain): self
    {
        return new self(
            __('":domain" is not a domain. Use the part of an address after the @, like acme.com.', ['domain' => $domain]),
            'sso.invalid_domain',
            422,
            ['domain' => $domain],
        );
    }

    /**
     * A connection that signs in no addresses is a connection nobody can
     * reach: sign-in starts from the domain.
     */
    public static function noDomains(): self
    {
        return new self(
            'Name at least one email domain — sign-in starts from the address somebody types.',
            'sso.domains_required',
            422,
        );
    }

    /**
     * Enforcing a connection nobody has signed in through.
     *
     * The one refusal here that exists to protect the person asking: a wrong
     * certificate plus enforcement is an organization nobody can enter, and
     * the only proof a connection works is somebody having used it.
     */
    public static function untested(): self
    {
        return new self(
            'Nobody has signed in through this connection yet. Sign in with single sign-on once — in a private window, so you keep this session — before requiring it of everybody.',
            'sso.untested',
            409,
        );
    }

    public static function stillEnforced(): self
    {
        return new self(
            'Single sign-on is required here. Stop requiring it before removing the connection, or nobody but administrators could sign in.',
            'sso.enforced',
            409,
        );
    }
}
