<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Action;

use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Application\Service\Webhook\WebhookDeliveries;
use App\Modules\Workflow\Application\Service\Webhook\WebhookEndpoints;

/**
 * Send what the rule saw to a registered endpoint (docs/02 §7, ADR 0048).
 *
 * `with: {endpoint_id}` — an endpoint, never a URL. Where data may go is
 * decided by whoever holds `webhook.manage`; a rule only chooses among those
 * places. That is the bound ADR 0014 asked for before this action could exist.
 *
 * The action does not make the request. It records a delivery and queues it,
 * so a receiver that takes five seconds to answer — or never does — cannot
 * stall the rule engine or roll back the rule run that asked for it.
 *
 * Idempotent through the delivery's dedupe key: the same change reaching the
 * same endpoint is one delivery, however many times the queue redelivers the
 * evaluation. Two rules sending the same change to the same endpoint are ALSO
 * one delivery — the payload names the change, not the rule, and this is the
 * posture `notify` already takes with its dedupe seed.
 */
final class WebhookAction implements WorkflowAction
{
    public function __construct(
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookDeliveries $deliveries,
    ) {}

    /** {@inheritDoc} */
    public function execute(
        array $config,
        string $subjectType,
        string $subjectId,
        array $facts,
        string $causationId,
        int $depth,
    ): array {
        // An endpoint that no longer exists throws, and the rule engine counts
        // it as a failure: a rule sending nowhere must be visible, and it
        // disables itself before it has failed for a month.
        $endpoint = $this->endpoints->find((string) ($config['endpoint_id'] ?? ''));

        $delivery = $this->deliveries->enqueue(
            $endpoint,
            'workflow.rule_matched',
            [
                'subject' => [
                    'type' => $subjectType,
                    'id' => $subjectId,
                    'reference' => $facts['reference'] ?? null,
                ],
                // Only the facts the rule vocabulary declares. That list is
                // documented, served, and held to reality by a test — so what
                // leaves this product is a published shape, not whatever a
                // listener happened to put in the array this week.
                'facts' => array_intersect_key($facts, RuleVocabulary::FIELDS),
                'causation_id' => $causationId,
            ],
            "{$causationId}:{$subjectId}:{$depth}",
        );

        return ['endpoint' => $endpoint->name, 'delivery_id' => $delivery->id];
    }
}
