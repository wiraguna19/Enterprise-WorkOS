<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Application\Service\Webhook;

use App\Modules\Workflow\Application\Service\RuleVocabulary;

/**
 * Every endpoint subscribed to an event gets it, without a rule (ADR 0048).
 *
 * The other half of "outbound integrations". A rule sends when its conditions
 * match; a subscription sends every time — "tell our warehouse about every
 * work item created" should not need a rule with no conditions whose only job
 * is to exist.
 *
 * **The same machinery, unchanged.** One delivery row per endpoint, through
 * `WebhookDeliveries::enqueue()`: the same signature, the same retries and
 * sweeper, the same failure count that switches a dead receiver off, the same
 * deliveries list on the settings screen. A second sending path would be a
 * second set of all of those.
 *
 * **The same shape a rule sends**, with the event named where a rule's says
 * `workflow.rule_matched`: the subject, and the facts the rule vocabulary
 * publishes — a documented list held to reality by a test, not whatever a
 * listener put in the array this week.
 *
 * **Once per event, however often the job runs.** The dedupe key is the
 * event's own id, fixed when the job was queued, so a retried job finds the
 * rows its first attempt wrote. And an endpoint that is ALSO the target of a
 * rule matching the same event gets both: they are different events on the
 * wire (`work_item.created` and `workflow.rule_matched`), asked for separately.
 */
final class WebhookSubscriptions
{
    public function __construct(
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookDeliveries $deliveries,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return int how many endpoints it was queued for
     */
    public function publish(string $event, string $subjectType, string $subjectId, array $facts, string $eventId): int
    {
        if (! in_array($event, WebhookEndpoints::SUBSCRIBABLE, strict: true)) {
            return 0;
        }

        $subscribers = $this->endpoints->subscribedTo($event);

        foreach ($subscribers as $endpoint) {
            $this->deliveries->enqueue(
                $endpoint,
                $event,
                [
                    'subject' => [
                        'type' => $subjectType,
                        'id' => $subjectId,
                        'reference' => $facts['reference'] ?? null,
                    ],
                    'facts' => array_intersect_key($facts, RuleVocabulary::FIELDS),
                    'event_id' => $eventId,
                ],
                "event:{$eventId}",
            );
        }

        return $subscribers->count();
    }
}
