<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contract;

/**
 * Pushing a domain event to whoever is currently looking (docs/07 §8).
 *
 * An interface rather than a direct call to a broadcaster, for the reason
 * docs/01 §8 gives: Reverb is the default and Centrifugo is the escape hatch
 * above roughly ten thousand concurrent sockets, and the swap has to be a
 * binding change rather than a rewrite of everything that publishes.
 *
 * It is also what keeps real-time OPTIONAL. Every caller here is enhancing a
 * page that is already correct when polled; the null implementation is a
 * complete, supported way to run this product (docs/07 §8).
 */
interface RealtimePublisher
{
    /**
     * @param  array<string, mixed>  $payload  what changed, never why it matters
     *
     * Payloads carry ids and nothing a subscriber could not already fetch. The
     * socket is not an authorization boundary for CONTENT: a listener refetches
     * through the API, which applies visibility, rather than trusting what
     * arrived over the wire.
     */
    public function publish(string $channel, string $event, array $payload = []): void;
}
