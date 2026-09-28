<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Webhook;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Domain\Exception\WebhookRefused;
use App\Modules\Workflow\Infrastructure\Eloquent\WebhookDeliveryModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WebhookEndpointModel;
use App\Modules\Workflow\Infrastructure\Job\DeliverWebhook;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Uid\UuidV7;

/**
 * Putting an event on its way to an endpoint, and trying to deliver it
 * (ADR 0048).
 *
 * The delivery ROW is the truth and the queued job is only a nudge. Everything
 * a person needs to answer "did they get it" — attempts, the last status code,
 * the last error, when the next try is — lives on the row, and a job that is
 * lost, duplicated or run late cannot make the row lie: an attempt CLAIMS the
 * row before it sends, and a claim is a conditional UPDATE the database decides.
 *
 * Retries belong to the row, not to the queue. The job runs once; a failed
 * attempt writes when the next one is due and asks for a delayed job. Queue
 * retries would re-run the whole attempt on any exception, including the ones
 * that already reached the receiver.
 */
final class WebhookDeliveries
{
    /** Five tries over roughly two and a half hours, then it is abandoned. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Seconds to wait after the Nth failed attempt.
     *
     * @var list<int>
     */
    public const BACKOFF = [60, 300, 1800, 7200];

    /** Consecutive abandoned deliveries before the endpoint switches itself off. */
    public const ENDPOINT_FAILURE_THRESHOLD = 5;

    /**
     * How long a claim holds the row.
     *
     * Longer than the request can take (timeout + connect timeout), so a second
     * worker holding a duplicate job never sends the same delivery while the
     * first is still waiting for an answer.
     */
    private const LEASE_SECONDS = 60;

    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly WebhookEndpoints $endpoints,
        private readonly DestinationGuard $guard,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Record an event for an endpoint and nudge the queue — once.
     *
     * The insert is `ON CONFLICT DO NOTHING` on (endpoint, dedupe key), so a
     * rule action run twice for one change, or a redelivered job, produces one
     * delivery. Only the call that actually inserted dispatches, and only after
     * the surrounding transaction commits: a worker must never pick up a job
     * for a row it cannot see yet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        WebhookEndpointModel $endpoint,
        string $event,
        array $payload,
        string $dedupeKey,
    ): WebhookDeliveryModel {
        $organizationId = $this->tenant->organizationId();

        $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
            'id' => (string) new UuidV7,
            'organization_id' => $organizationId,
            'endpoint_id' => $endpoint->id,
            'event' => $event,
            'dedupe_key' => mb_substr($dedupeKey, 0, 200),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var WebhookDeliveryModel $delivery */
        $delivery = WebhookDeliveryModel::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('dedupe_key', mb_substr($dedupeKey, 0, 200))
            ->firstOrFail();

        if ($inserted === 1) {
            DeliverWebhook::dispatch($organizationId, $delivery->id)->afterCommit();
        }

        return $delivery;
    }

    /**
     * A hand-made event, so somebody setting up a receiver can see one arrive
     * — and see what the signature looks like — before any rule fires.
     */
    public function ping(WebhookEndpointModel $endpoint): WebhookDeliveryModel
    {
        return $this->enqueue(
            $endpoint,
            'ping',
            ['message' => 'A test event from Enterprise Work OS. Nothing happened; this checks the connection.'],
            'ping:'.(string) new UuidV7,
        );
    }

    /**
     * The latest deliveries to one endpoint, newest first.
     *
     * @return Collection<int, WebhookDeliveryModel>
     */
    public function recent(WebhookEndpointModel $endpoint, int $limit = 50): Collection
    {
        return WebhookDeliveryModel::query()
            ->where('endpoint_id', $endpoint->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Try to deliver once.
     *
     * @return int|null seconds until the next attempt should be made, or null
     *                  when there is nothing more to do
     */
    public function attempt(string $deliveryId): ?int
    {
        if (! $this->claim($deliveryId)) {
            return null;
        }

        /** @var WebhookDeliveryModel $delivery */
        $delivery = WebhookDeliveryModel::query()->findOrFail($deliveryId);
        /** @var WebhookEndpointModel $endpoint */
        $endpoint = WebhookEndpointModel::query()->findOrFail($delivery->endpoint_id);

        if (! $endpoint->is_active) {
            $this->finish($delivery, 'refused', null, 'The endpoint is switched off.');

            return null;
        }

        try {
            $destination = $this->guard->resolve($endpoint->url);
        } catch (WebhookRefused $e) {
            // A name that does not resolve may resolve in an hour. An address
            // that resolves somewhere private will not become public by
            // waiting, so retrying it would only send the same refusal five
            // times.
            if ($e->errorCode() === 'webhook.destination_unresolvable') {
                return $this->failed($delivery, $endpoint, null, $e->getMessage());
            }

            $this->finish($delivery, 'refused', null, $e->getMessage());

            return null;
        }

        $body = json_encode([
            'id' => $delivery->id,
            'event' => $delivery->event,
            'created_at' => $delivery->created_at->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(3)
                // A redirect is a second destination nobody checked. A 3xx is
                // recorded as the answer, and the receiver fixes its address.
                ->withoutRedirecting()
                // Connect to the address the guard approved, not to whatever
                // the name resolves to by the time curl asks (DNS rebinding).
                ->withOptions(['curl' => [
                    CURLOPT_RESOLVE => ["{$destination['host']}:{$destination['port']}:{$destination['ip']}"],
                ]])
                ->withHeaders([
                    'User-Agent' => 'EnterpriseWorkOS-Webhooks/1',
                    'X-WorkOS-Event' => $delivery->event,
                    'X-WorkOS-Delivery' => $delivery->id,
                    'X-WorkOS-Signature' => $this->signature($endpoint, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            return $this->failed($delivery, $endpoint, null, 'Could not connect: '.$e->getMessage());
        }

        if ($response->successful()) {
            $this->finish($delivery, 'delivered', $response->status(), null);

            if ($endpoint->failure_count > 0) {
                $endpoint->forceFill(['failure_count' => 0])->save();
            }

            return null;
        }

        // The status only. The body the receiver sent back is NOT stored: this
        // product just made a request somewhere a customer chose, and printing
        // what came back is how a webhook screen becomes a way to read pages
        // nobody meant to expose.
        return $this->failed(
            $delivery,
            $endpoint,
            $response->status(),
            "The receiver answered {$response->status()}.",
        );
    }

    /**
     * `t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`.
     *
     * The timestamp is inside the signed string so a captured delivery cannot
     * be replayed next week with a fresh date, and the receiver can refuse
     * anything older than a few minutes. `v1` leaves room for a second scheme
     * without breaking the first.
     */
    public function signature(WebhookEndpointModel $endpoint, string $timestamp, string $body): string
    {
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $this->endpoints->secretOf($endpoint));

        return "t={$timestamp},v1={$mac}";
    }

    /**
     * Take the row for one attempt, or learn that somebody else has it.
     *
     * The UPDATE is the lock: it succeeds only for a pending row that is due,
     * and it moves `next_attempt_at` a lease into the future in the same
     * statement, so a duplicate job arriving a second later finds nothing due.
     * No transaction is held open across the HTTP request.
     */
    private function claim(string $deliveryId): bool
    {
        $claimed = DB::table('webhook_deliveries')
            ->where('organization_id', $this->tenant->organizationId())
            ->where('id', $deliveryId)
            ->where('status', 'pending')
            ->where(function (Builder $query): void {
                // Grouped: the tenant condition above must not be OR-ed away.
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    /** A failed attempt: schedule the next, or give up and count it against the endpoint. */
    private function failed(
        WebhookDeliveryModel $delivery,
        WebhookEndpointModel $endpoint,
        ?int $status,
        string $error,
    ): ?int {
        if ($delivery->attempts >= self::MAX_ATTEMPTS) {
            $this->finish($delivery, 'abandoned', $status, $error);
            $this->countAbandoned($endpoint);

            return null;
        }

        $delay = self::BACKOFF[$delivery->attempts - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1];

        $delivery->forceFill([
            'last_status_code' => $status,
            'last_error' => mb_substr($error, 0, 500),
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        return $delay;
    }

    private function finish(WebhookDeliveryModel $delivery, string $status, ?int $code, ?string $error): void
    {
        $delivery->forceFill([
            'status' => $status,
            'last_status_code' => $code,
            'last_error' => $error === null ? null : mb_substr($error, 0, 500),
            'next_attempt_at' => null,
            'delivered_at' => $status === 'delivered' ? now() : null,
        ])->save();
    }

    /**
     * An endpoint that keeps abandoning deliveries switches itself off, with
     * the reason written where the administrator will look — the posture a
     * failing rule already has. Queueing work forever for a receiver that is
     * gone is a slow leak nobody sees.
     */
    private function countAbandoned(WebhookEndpointModel $endpoint): void
    {
        $failures = $endpoint->failure_count + 1;
        $attributes = ['failure_count' => $failures];

        if ($failures >= self::ENDPOINT_FAILURE_THRESHOLD) {
            $attributes['is_active'] = false;
            $attributes['disabled_reason'] = "Switched off after {$failures} deliveries in a row were abandoned.";
        }

        $endpoint->forceFill($attributes)->save();
    }
}
