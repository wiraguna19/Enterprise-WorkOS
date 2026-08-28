<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Realtime;

use App\Modules\Platform\Domain\Contract\RealtimePublisher;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes through Laravel's broadcaster (Reverb by default, docs/01 §8).
 *
 * Failures are swallowed, deliberately and loudly-in-the-log. The socket is an
 * enhancement over a system that is already correct: if the broadcaster is down
 * mid-request, the right outcome is a comment that saved and a page that
 * refreshes a moment later — not a 500 on a write that succeeded. This is the
 * one place that decision is made, so no caller has to remember it.
 */
final class BroadcastPublisher implements RealtimePublisher
{
    public function __construct(private readonly BroadcastFactory $broadcaster) {}

    /** @param array<string, mixed> $payload */
    public function publish(string $channel, string $event, array $payload = []): void
    {
        try {
            $this->broadcaster->connection()->broadcast([$channel], $event, $payload);
        } catch (Throwable $exception) {
            Log::warning('realtime.publish_failed', [
                'channel' => $channel,
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
