<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Saml;

use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * The one place this product speaks SAML (ADR 0052).
 *
 * **Installed, not written.** TOTP was written by hand against RFC 6238 (ADR
 * 0030) because it is forty lines of arithmetic. Verifying an XML signature is
 * not: the attacks on it — signature wrapping, comments inside a NameID,
 * a reference that points at a different element than the one read — are
 * the kind a hand-rolled verifier learns about from an incident report.
 * `onelogin/php-saml` is the toolkit most PHP service providers run, and
 * everything it does is confined to this class so the rest of Identity never
 * sees a DOMDocument.
 *
 * ## Why the URL has to be told, not discovered
 *
 * The toolkit checks that a response was addressed to the URL it arrived at
 * (`Destination`, and `Recipient` on the subject confirmation), and it learns
 * that URL from `$_SERVER`. Here the browser posts to the WEB server — the
 * session cookie is set there (docs/06 §1) — and the web server hands the
 * response to this API, so `$_SERVER` describes the wrong URL. The toolkit's
 * own escape hatch is `Utils::setBaseURL()`; it is set to the assertion
 * consumer's directory for the length of one verification and cleared after,
 * with `REQUEST_URI` pointed at the consumer. Static state, restored in a
 * `finally` — ugly, contained, and the alternative (switching the check off)
 * is the attack the check exists for.
 */
final class SamlToolkit
{
    /** Where the browser posts a response: a web route, not an API one. */
    private const ACS_PATH = '/api/auth/sso/acs';

    private const METADATA_PATH = '/api/auth/sso/metadata';

    public function __construct(
        /** The web application's origin, e.g. https://work.example.com. */
        private readonly string $baseUrl,
    ) {}

    /**
     * What an identity provider's administrator types into their console.
     *
     * The entity id IS the metadata URL: a URL is the conventional shape, and
     * one that answers with the metadata is one fewer thing to copy by hand.
     *
     * @return array{entity_id: string, acs_url: string, metadata_url: string}
     */
    public function serviceProvider(): array
    {
        return [
            'entity_id' => $this->url(self::METADATA_PATH),
            'acs_url' => $this->url(self::ACS_PATH),
            'metadata_url' => $this->url(self::METADATA_PATH),
        ];
    }

    /** The service provider's metadata document, for an IdP that imports it. */
    public function metadata(): string
    {
        $settings = new Settings($this->settingsArray(null), true);

        return $settings->getSPMetadata();
    }

    /**
     * Is this a certificate at all? Asked when an administrator saves one, so
     * a paste that lost a line is refused on the form rather than discovered
     * as "every sign-in fails" by everybody else.
     */
    public function isCertificate(string $pem): bool
    {
        $normalized = Utils::formatCert($pem);

        return @openssl_x509_read($normalized) !== false;
    }

    /**
     * Where to send the browser, and the id the answer must quote back.
     *
     * HTTP-Redirect binding, unsigned: the request carries nothing secret, and
     * a signed AuthnRequest would need a service-provider key this product
     * would then have to rotate. `RelayState` is how the answer is matched to
     * the request that asked for it (see SingleSignOn::start()).
     *
     * @return array{url: string, request_id: string}
     */
    public function authnRequest(IdentityProvider $idp, string $relayState): array
    {
        $request = new AuthnRequest(new Settings($this->settingsArray($idp)));

        $query = http_build_query([
            'SAMLRequest' => $request->getRequest(),
            'RelayState' => $relayState,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => $idp->ssoUrl.(str_contains($idp->ssoUrl, '?') ? '&' : '?').$query,
            'request_id' => $request->getId(),
        ];
    }

    /**
     * Verify a response and say who it vouches for — or refuse, with the
     * toolkit's reason.
     *
     * Everything the toolkit checks is on: the signature against THIS
     * organization's certificate (response or assertion — at least one, and
     * every one present must verify), the issuer, the audience, the
     * destination and recipient, the validity window, and that the response
     * answers `$requestId` and nothing else. Unsolicited responses are refused
     * outright (ADR 0052).
     *
     * @throws SamlRejected
     */
    public function verify(IdentityProvider $idp, string $samlResponse, string $requestId): SamlAssertion
    {
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $savedQuery = $_SERVER['QUERY_STRING'] ?? null;
        $savedScript = $_SERVER['SCRIPT_NAME'] ?? null;

        $acs = $this->url(self::ACS_PATH);

        try {
            Utils::setBaseURL(substr($acs, 0, (int) strrpos($acs, '/') + 1));
            $_SERVER['REQUEST_URI'] = self::ACS_PATH;
            $_SERVER['SCRIPT_NAME'] = self::ACS_PATH;
            unset($_SERVER['QUERY_STRING']);

            try {
                $response = new Response(new Settings($this->settingsArray($idp)), $samlResponse);
            } catch (Throwable) {
                throw new SamlRejected('The response could not be read as SAML.');
            }

            try {
                $valid = $response->isValid($requestId);
            } catch (Throwable $error) {
                throw new SamlRejected($error->getMessage());
            }

            if (! $valid) {
                throw new SamlRejected((string) $response->getError(false));
            }

            try {
                $nameId = (string) $response->getNameId();
                /** @var array<string, list<string>> $attributes */
                $attributes = $response->getAttributes();
            } catch (Throwable $error) {
                throw new SamlRejected($error->getMessage());
            }

            return new SamlAssertion(
                nameId: $nameId,
                email: $this->emailFrom($nameId, $attributes),
            );
        } finally {
            // An empty base URL is the toolkit's reset: it clears the host,
            // protocol, port and path set above.
            Utils::setBaseURL('');

            $this->restore('REQUEST_URI', $savedUri);
            $this->restore('QUERY_STRING', $savedQuery);
            $this->restore('SCRIPT_NAME', $savedScript);
        }
    }

    /**
     * The address a response vouches for.
     *
     * An `email` attribute first — in whichever of the three spellings the
     * common IdPs use — because an attribute NAMED email says what it is, while
     * a NameID is whatever identifier the IdP was configured to send. A NameID
     * that is shaped like an address is the fallback. Neither: null, and the
     * sign-in is refused by the caller with a sentence the administrator can
     * act on.
     *
     * @param  array<string, list<string>>  $attributes
     */
    private function emailFrom(string $nameId, array $attributes): ?string
    {
        foreach (['email', 'mail', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'] as $name) {
            $value = $attributes[$name][0] ?? null;

            if (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false) {
                return mb_strtolower(trim($value));
            }
        }

        if (filter_var(trim($nameId), FILTER_VALIDATE_EMAIL) !== false) {
            return mb_strtolower(trim($nameId));
        }

        return null;
    }

    /**
     * The toolkit's settings for one organization's IdP — or for none, when
     * all that is wanted is this product's own metadata.
     *
     * @return array<string, mixed>
     */
    private function settingsArray(?IdentityProvider $idp): array
    {
        $sp = $this->serviceProvider();

        $settings = [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $sp['entity_id'],
                'assertionConsumerService' => [
                    'url' => $sp['acs_url'],
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_EMAIL_ADDRESS,
            ],
            'security' => [
                // One valid signature is required either way (the toolkit
                // refuses a response with none). Which element carries it is
                // the IdP's choice: Okta and Entra sign the assertion, others
                // sign the response, mocksaml signs both.
                'wantAssertionsSigned' => false,
                'wantMessagesSigned' => false,
                'wantAssertionsEncrypted' => false,
                'wantNameIdEncrypted' => false,
                'wantXMLValidation' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                // No AuthnContext demanded: requiring "password over TLS"
                // makes an IdP that used a passkey or a second factor refuse
                // the request, which punishes the stronger sign-in.
                'requestedAuthnContext' => false,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];

        if ($idp !== null) {
            $settings['idp'] = [
                'entityId' => $idp->entityId,
                'singleSignOnService' => [
                    'url' => $idp->ssoUrl,
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => Utils::formatCert($idp->certificate, false),
            ];
        }

        return $settings;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    private function restore(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key]);

            return;
        }

        $_SERVER[$key] = $value;
    }
}
