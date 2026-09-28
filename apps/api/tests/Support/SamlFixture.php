<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

/**
 * An identity provider in a box, for the single sign-on tests (ADR 0052).
 *
 * Builds SAML responses the way a real IdP does — an assertion signed with a
 * private key whose certificate the organization pasted in — so the tests
 * exercise the real verifier rather than a mock of it. A mocked verifier would
 * pass every test here and prove nothing about the one part that matters.
 *
 * Keys are made once per process: RSA generation is the slowest thing in the
 * file, and nothing about a test depends on the key being fresh.
 */
final class SamlFixture
{
    public const ENTITY_ID = 'https://idp.acme.test/saml';

    public const SSO_URL = 'https://idp.acme.test/sso';

    /** @var array<string, array{key: string, certificate: string}> */
    private static array $pairs = [];

    /**
     * A key and a self-signed certificate, by name — `trusted` is the one the
     * organization configured, anything else is an impostor's.
     *
     * @return array{key: string, certificate: string}
     */
    public static function pair(string $name = 'trusted'): array
    {
        if (isset(self::$pairs[$name])) {
            return self::$pairs[$name];
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('openssl could not make a key.');
        }

        $csr = openssl_csr_new(['commonName' => "{$name}.idp.test"], $key, ['digest_alg' => 'sha256']);

        if ($csr === false || $csr === true) {
            throw new RuntimeException('openssl could not make a signing request.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new RuntimeException('openssl could not sign a certificate.');
        }

        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($certificate, $certificatePem);

        return self::$pairs[$name] = ['key' => (string) $keyPem, 'certificate' => (string) $certificatePem];
    }

    /**
     * A base64 response, as the browser would post it.
     *
     * Options: `request_id`, `acs_url`, `audience` (required); `email`,
     * `issuer`, `signed_by` (a key name, 'trusted' by default), `sign`
     * ('assertion', 'response' or 'none'), `expires_in` (seconds), and
     * `tamper_email` (changed AFTER signing).
     *
     * @param  array<string, mixed>  $options
     */
    public static function response(array $options): string
    {
        $option = static fn (string $key, string $default = ''): string => is_scalar($options[$key] ?? null)
            ? (string) $options[$key]
            : $default;

        $email = $option('email', 'rina@acme.test');
        $issuer = $option('issuer', self::ENTITY_ID);
        $sign = $option('sign', 'assertion');
        $pair = self::pair($option('signed_by', 'trusted'));
        $expiresIn = (int) $option('expires_in', '300');
        $tamper = $option('tamper_email');

        $now = time();
        $instant = gmdate('Y-m-d\TH:i:s\Z', $now);
        $notBefore = gmdate('Y-m-d\TH:i:s\Z', $now - 60);
        $notAfter = gmdate('Y-m-d\TH:i:s\Z', $now + $expiresIn);

        $responseId = '_r'.bin2hex(random_bytes(16));
        $assertionId = '_a'.bin2hex(random_bytes(16));

        $requestId = htmlspecialchars($option('request_id'), ENT_XML1);
        $acs = htmlspecialchars($option('acs_url'), ENT_XML1);
        $audience = htmlspecialchars($option('audience'), ENT_XML1);
        $issuerXml = htmlspecialchars($issuer, ENT_XML1);
        $emailXml = htmlspecialchars($email, ENT_XML1);

        $assertion = <<<XML
            <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="{$assertionId}" Version="2.0" IssueInstant="{$instant}"><saml:Issuer>{$issuerXml}</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$emailXml}</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData InResponseTo="{$requestId}" NotOnOrAfter="{$notAfter}" Recipient="{$acs}"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notAfter}"><saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="{$instant}" SessionIndex="{$assertionId}"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement><saml:Attribute Name="email" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic"><saml:AttributeValue xsi:type="xs:string">{$emailXml}</saml:AttributeValue></saml:Attribute></saml:AttributeStatement></saml:Assertion>
            XML;

        if ($sign === 'assertion') {
            $assertion = self::withoutDeclaration(self::sign($assertion, $pair));
        }

        $response = <<<XML
            <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$instant}" Destination="{$acs}" InResponseTo="{$requestId}"><saml:Issuer>{$issuerXml}</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>{$assertion}</samlp:Response>
            XML;

        if ($sign === 'response') {
            $response = self::sign($response, $pair);
        }

        // After signing, the way an attacker would: the address changes and
        // the signature does not.
        if ($tamper !== '') {
            $response = str_replace(
                '>'.$emailXml.'<',
                '>'.htmlspecialchars($tamper, ENT_XML1).'<',
                $response,
            );
        }

        return base64_encode($response);
    }

    /**
     * An enveloped signature over the root element, placed after its Issuer
     * where the schema wants it.
     *
     * Written out rather than borrowed from the toolkit's `Utils::addSign()`,
     * which puts the signature FIRST on anything that is not a protocol
     * message — so an assertion it signs fails the schema the verifier checks,
     * and every test built on it would have been testing that instead.
     *
     * @param  array{key: string, certificate: string}  $pair
     */
    private static function sign(string $xml, array $pair): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $root = $dom->documentElement;

        if (! $root instanceof DOMElement) {
            throw new RuntimeException('Nothing to sign.');
        }

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($pair['key']);

        $signature = new XMLSecurityDSig;
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        // The LIST form, with one element: `addReference()` is documented as
        // taking a whole document, which would sign it with an empty URI
        // rather than by the element's ID — and the verifier looks for the ID.
        $signature->addReferenceList(
            [$root],
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );
        $signature->sign($key);
        $signature->add509Cert($pair['certificate']);

        $issuer = $root->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);

        $signature->insertSignature($root, $issuer?->nextSibling);

        return (string) $dom->saveXML();
    }

    private static function withoutDeclaration(string $xml): string
    {
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $xml);
    }
}
