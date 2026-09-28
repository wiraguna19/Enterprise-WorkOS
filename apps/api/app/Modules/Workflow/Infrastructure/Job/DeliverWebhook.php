<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Infrastructure\Job;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Workflow\Application\Service\Webhook\WebhookDeliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A nudge to attempt one webhook delivery (ADR 0048).
 *
 * One try, on purpose. The delivery row owns its retries — how many, when, and
 * why the last one failed — and a queue retry would re-run an attempt whose
 * request may already have reached the receiver. When an attempt asks for
 * another, this job schedules a fresh one with the delay the row chose.
 *
 * On the `low` queue: a slow receiver on the other side of the internet must
 * not sit in front of rule evaluation and notifications.
 *
 * Carries its tenant explicitly, like every job here: a queued job has no
 * request and therefore no ambient organization (docs/01 §6).
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The request's own timeouts are seconds; anything past this is stuck. */
    public int $timeout = 30;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $deliveryId,
    ) {
        $this->onQueue('low');
    }

    public function handle(TenantContext $tenant, WebhookDeliveries $deliveries): void
    {
        $retryIn = $tenant->runFor(
            $this->organizationId,
            fn (): ?int => $deliveries->attempt($this->deliveryId),
        );

        if (is_int($retryIn)) {
            self::dispatch($this->organizationId, $this->deliveryId)
                ->delay(now()->addSeconds($retryIn));
        }
    }
}
