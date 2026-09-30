<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controller;

use App\Modules\Platform\Http\Controller\ApiController;
use App\Modules\Platform\Http\Response\ApiResponse;
use App\Modules\Workflow\Application\Service\Webhook\WebhookDeliveries;
use App\Modules\Workflow\Application\Service\Webhook\WebhookEndpoints;
use App\Modules\Workflow\Infrastructure\Eloquent\WebhookDeliveryModel;
use App\Modules\Workflow\Infrastructure\Eloquent\WebhookEndpointModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administering where this organization's events may be sent (ADR 0048).
 *
 * Every route is `webhook.manage`, reads included: the list holds addresses,
 * and a webhook address is often a credential in its own right. The rule
 * builder, whose author holds `workflow.manage` and not this, gets the NAMES
 * through the rule vocabulary — the lesson ADR 0038 paid for, that a form must
 * not read an administration endpoint.
 */
final class WebhookEndpointController extends ApiController
{
    public function __construct(
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookDeliveries $deliveries,
    ) {}

    public function index(): ApiResponse
    {
        return ApiResponse::collection(
            $this->endpoints->all()->map($this->present(...))->all(),
            // Served, not copied into the form: the list is the migration's
            // CHECK and this class's constant, and a third copy in TypeScript
            // is the one that would drift (ADR 0047's rule).
            ['subscribable' => WebhookEndpoints::SUBSCRIBABLE],
        );
    }

    /**
     * Register one. The response carries the secret, and nothing else ever
     * will — the screen says so beside it.
     */
    public function store(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2000'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEndpoints::SUBSCRIBABLE)],
        ]);

        /** @var list<string> $events */
        $events = $validated['events'] ?? [];

        [$endpoint, $secret] = $this->endpoints->register($validated['name'], $validated['url'], $events);

        return $this->created($this->present($endpoint) + ['secret' => $secret]);
    }

    public function update(Request $request, string $id): ApiResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'url' => ['sometimes', 'string', 'max:2000'],
            // An empty list is a real answer: "rules only, no subscriptions".
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEndpoints::SUBSCRIBABLE)],
        ]);

        /** @var array{name?: string, url?: string, events?: list<string>} $changes */
        $changes = $validated;

        return $this->ok($this->present($this->endpoints->update($this->endpoints->find($id), $changes)));
    }

    /** On or off — the two directions of one switch, like a rule's. */
    public function setActive(Request $request, string $id): ApiResponse
    {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $endpoint = $this->endpoints->setActive($this->endpoints->find($id), (bool) $validated['active']);

        return $this->ok($this->present($endpoint));
    }

    /** A new secret, shown once, effective immediately. */
    public function rotateSecret(string $id): ApiResponse
    {
        $endpoint = $this->endpoints->find($id);

        return $this->ok(['secret' => $this->endpoints->rotateSecret($endpoint)]);
    }

    public function destroy(string $id): ApiResponse
    {
        $this->endpoints->delete($this->endpoints->find($id));

        return $this->noContent();
    }

    /** Send a `ping` now, so a receiver can be checked before any rule fires. */
    public function test(string $id): ApiResponse
    {
        $delivery = $this->deliveries->ping($this->endpoints->find($id));

        return $this->created($this->presentDelivery($delivery->refresh()));
    }

    /**
     * The latest fifty deliveries.
     *
     * Bounded rather than paged: the question this screen answers is "is it
     * working NOW", and fifty rows answer it. A full history is a retention
     * and export question (ADR 0048).
     */
    public function deliveries(string $id): ApiResponse
    {
        return ApiResponse::collection(
            $this->deliveries->recent($this->endpoints->find($id))
                ->map($this->presentDelivery(...))
                ->all(),
        );
    }

    /** @return array<string, mixed> */
    private function present(WebhookEndpointModel $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'is_active' => $endpoint->is_active,
            'failure_count' => $endpoint->failure_count,
            'disabled_reason' => $endpoint->disabled_reason,
            // Which rules would break if it went away — the answer the delete
            // refusal gives, available before anybody tries.
            'events' => $endpoint->events,
            'rules' => $this->endpoints->rulesSendingTo($endpoint),
            'created_at' => $endpoint->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentDelivery(WebhookDeliveryModel $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'last_status_code' => $delivery->last_status_code,
            'last_error' => $delivery->last_error,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at->toIso8601String(),
        ];
    }
}
