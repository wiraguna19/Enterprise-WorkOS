<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Realtime;

use App\Modules\Platform\Domain\Contract\RealtimePublisher;

/**
 * Real-time, switched off.
 *
 * Not a test double — the default. A deployment with no socket server is a
 * supported way to run this product: every screen is correct when polled, and
 * the socket only removes the wait (docs/07 §8). Making "off" the default is
 * what keeps that claim true, because the path with no broadcaster is the one
 * everybody's test suite exercises.
 */
final class NullPublisher implements RealtimePublisher
{
    /** @param array<string, mixed> $payload */
    public function publish(string $channel, string $event, array $payload = []): void
    {
        // Deliberately nothing. Not even a log line: a product running without
        // a socket server is not doing anything worth warning about.
    }
}
