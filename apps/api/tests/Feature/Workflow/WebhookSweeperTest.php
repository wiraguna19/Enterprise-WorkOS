<?php

declare(strict_types=1);

use App\Modules\Workflow\Infrastructure\Job\DeliverWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Uid\UuidV7;

/**
 * The sweeper for deliveries the queue lost (ADR 0048).
 *
 * What it must find is narrow: pending, and well past due. A healthy retry
 * that is due this minute belongs to its own delayed job, and a delivered or
 * abandoned row belongs to nobody.
 */
const SWEEP_ACME = '01900000-0000-7000-8000-0000000000ac';

/** A delivery row, with the columns the sweep reads set as asked. */
function deliveryRow(string $endpointId, string $status, ?DateTimeInterface $nextAttemptAt, DateTimeInterface $createdAt): string
{
    $id = (string) new UuidV7;

    DB::table('webhook_deliveries')->insert([
        'id' => $id,
        'organization_id' => SWEEP_ACME,
        'endpoint_id' => $endpointId,
        'event' => 'ping',
        'dedupe_key' => 'sweep:'.$id,
        'payload' => '{}',
        'status' => $status,
        'next_attempt_at' => $nextAttemptAt,
        'created_at' => $createdAt,
    ]);

    return $id;
}

it('re-dispatches the deliveries a queue lost, and only those', function (): void {
    Queue::fake();

    $endpoint = actingWithinTenant(SWEEP_ACME, fn (): string => probeWebhookEndpointIn(SWEEP_ACME));

    // Lost: a retry due twenty minutes ago that nobody took.
    deliveryRow($endpoint, 'pending', now()->subMinutes(20), now()->subHour());
    // Lost: never attempted at all, and old enough to say so.
    deliveryRow($endpoint, 'pending', null, now()->subMinutes(20));

    // Healthy: due a moment ago — its own job is about to run.
    deliveryRow($endpoint, 'pending', now()->subMinute(), now()->subHour());
    // Healthy: waiting for a future retry.
    deliveryRow($endpoint, 'pending', now()->addMinutes(10), now()->subHour());
    // Finished, either way.
    deliveryRow($endpoint, 'delivered', null, now()->subDay());
    deliveryRow($endpoint, 'abandoned', null, now()->subDay());

    $this->artisan('workflow:nudge-webhook-deliveries')
        ->expectsOutputToContain('Nudged 2')
        ->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 2);
});
