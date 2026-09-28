<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Webhook;

use App\Modules\Workflow\Domain\Exception\WebhookRefused;

/**
 * Where a webhook may NOT go (ADR 0048).
 *
 * A webhook is this server making a request to an address a customer typed,
 * from inside the network it runs in. Without this class that is server-side
 * request forgery with a settings screen: `https://169.254.169.254/` reads the
 * cloud provider's metadata service, `https://10.0.0.5:6379/` talks to Redis,
 * and the delivery log would helpfully print what came back.
 *
 * So an address must be https, carry no credentials, and resolve ONLY to public
 * addresses — every A record, not the first, since one name can answer with
 * several. And the answer is PINNED: {@see resolve()} returns the address it
 * checked, and the sender connects to that address rather than asking DNS
 * again, because a name that resolved to a public address a moment ago can
 * resolve to 10.0.0.5 now (DNS rebinding). Checking a name and then connecting
 * to whatever it says next is checking nothing.
 *
 * IPv4 only, and that is a limitation written down rather than hidden: an
 * IPv6-only receiver is refused as unresolvable, which is safe and fixable,
 * where half-checking IPv6 would be neither.
 */
final class DestinationGuard
{
    /**
     * CGNAT space. Not private by RFC 1918, so PHP's private-range flag lets it
     * through, and on a cloud network it is exactly where internal services
     * live.
     */
    private const SHARED_ADDRESS_SPACE = ['100.64.0.0', 10];

    /**
     * Check an address and say where to connect — or refuse it, with a
     * sentence naming why, when it is somewhere this product will not send to
     * or does not resolve at all.
     *
     * @return array{host: string, port: int, ip: string}
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw WebhookRefused::destinationNotAllowed('it is not a URL.');
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            // The body is signed, not secret — but it carries this
            // organization's work, and sending it in the clear is the one
            // configuration nobody should be able to choose by accident.
            throw WebhookRefused::destinationNotAllowed('only https addresses are accepted.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WebhookRefused::destinationNotAllowed(
                'credentials in the address would be stored and shown in plain text. Put them in the receiver instead.',
            );
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? 443;

        $addresses = $this->addressesOf($host);

        if ($addresses === []) {
            throw WebhookRefused::unresolvable($host);
        }

        foreach ($addresses as $ip) {
            if (! $this->isPublic($ip)) {
                throw WebhookRefused::destinationNotAllowed(
                    "{$host} resolves to {$ip}, which is a private or reserved address.",
                );
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** @return list<string> */
    private function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return [$host];
        }

        // An IPv6 literal is refused rather than half-checked: mapped forms
        // like ::ffff:10.0.0.5 are exactly where a range check goes wrong.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return [];
        }

        $resolved = gethostbynamel($host);

        return $resolved === false ? [] : $resolved;
    }

    private function isPublic(string $ip): bool
    {
        // Private (10/8, 172.16/12, 192.168/16) and reserved (0/8, 127/8,
        // 169.254/16, 240/4) together — loopback and the metadata service are
        // in the second set, which is the one that matters most.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return false;
        }

        [$network, $bits] = self::SHARED_ADDRESS_SPACE;
        $mask = -1 << (32 - $bits);

        return ((int) ip2long($ip) & $mask) !== ((int) ip2long($network) & $mask);
    }
}
