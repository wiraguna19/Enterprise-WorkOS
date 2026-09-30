<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * An endpoint subscribed to an event receives it, without a rule (ADR 0048).
 *
 * The receiver is an IP literal, as in WebhookTest, so no test touches DNS or
 * the network: `Http::fake()` answers, and `preventStrayRequests()` makes any
 * request this file did not arrange a failure.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['93.184.216.34/*' => Http::response('ok', 200)]);

    $this->admin = $this->loginAs('rina@acme.test');      // webhook.manage
    $this->manager = $this->loginAs('ahmad@acme.test');   // creates work
});

/** @param list<string> $events */
function subscribedEndpoint(string $token, array $events, string $name = 'Warehouse'): string
{
    return (string) test()->withToken($token)
        ->postJson('/api/v1/webhook-endpoints', [
            'name' => $name,
            'url' => 'https://93.184.216.34/hook',
            'events' => $events,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.events', $events)
        ->json('data.id');
}

/** @param array<string, mixed> $extra */
function subscriptionItem(string $token, array $extra = []): string
{
    $project = test()->withToken($token)->getJson('/api/v1/projects/ENG')->json('data.id');

    return (string) test()->withToken($token)->postJson('/api/v1/work-items', [
        'title' => 'Announced to subscribers',
        'type' => 'task',
        'project_id' => $project,
    ] + $extra)->assertCreated()->json('data.reference');
}

/** @return list<object{event: string, payload: string, status: string}> */
function deliveriesTo(string $endpointId): array
{
    /** @var list<object{event: string, payload: string, status: string}> $rows */
    $rows = DB::table('webhook_deliveries')
        ->where('endpoint_id', $endpointId)
        ->orderBy('created_at')
        ->get(['event', 'payload', 'status'])
        ->all();

    return $rows;
}

it('delivers every event an endpoint subscribed to, with no rule anywhere', function (): void {
    $endpoint = subscribedEndpoint($this->admin, ['work_item.created']);

    $reference = subscriptionItem($this->manager);

    $rows = deliveriesTo($endpoint);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->event)->toBe('work_item.created')
        ->and($rows[0]->status)->toBe('delivered');

    /** @var array{subject: array{reference: string|null}, facts: array<string, mixed>} $payload */
    $payload = json_decode($rows[0]->payload, true);

    expect($payload['subject']['reference'])->toBe($reference)
        ->and($payload['facts'])->toHaveKey('priority');

    Http::assertSent(fn (Request $request): bool => $request->header('X-WorkOS-Event')[0] === 'work_item.created');
});

it('delivers only what was subscribed to', function (): void {
    $endpoint = subscribedEndpoint($this->admin, ['work_item.assigned']);

    // Created AND assigned in one request: two events, one subscribed.
    subscriptionItem($this->manager, ['assignee_id' => '01900000-0000-7000-8000-000000000205']);

    expect(array_column(deliveriesTo($endpoint), 'event'))->toBe(['work_item.assigned']);
});

it('sends nothing to an endpoint that is switched off, or subscribed to nothing', function (): void {
    $off = subscribedEndpoint($this->admin, ['work_item.created'], 'Switched off');
    $none = subscribedEndpoint($this->admin, [], 'Rules only');

    $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$off}/active", ['active' => false])
        ->assertOk();

    subscriptionItem($this->manager);

    expect(deliveriesTo($off))->toBe([])->and(deliveriesTo($none))->toBe([]);
});

it('changes subscriptions on an edit, and an empty list means rules only', function (): void {
    $endpoint = subscribedEndpoint($this->admin, ['work_item.created']);

    $this->withToken($this->admin)
        ->patchJson("/api/v1/webhook-endpoints/{$endpoint}", ['events' => []])
        ->assertOk()
        ->assertJsonPath('data.events', []);

    subscriptionItem($this->manager);

    expect(deliveriesTo($endpoint))->toBe([]);
});

it('refuses an event nothing emits, and serves the list of those that are', function (): void {
    // `approval.decided` is a rule trigger that nothing dispatches: offering
    // it here would be a subscription that can never be delivered.
    $this->withToken($this->admin)->postJson('/api/v1/webhook-endpoints', [
        'name' => 'Hopeful',
        'url' => 'https://93.184.216.34/hook',
        'events' => ['approval.decided'],
    ])->assertUnprocessable();

    $this->withToken($this->admin)
        ->getJson('/api/v1/webhook-endpoints')
        ->assertOk()
        ->assertJsonPath('meta.subscribable', ['work_item.created', 'work_item.assigned', 'work_item.status_changed']);
});
