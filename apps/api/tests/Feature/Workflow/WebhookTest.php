<?php

declare(strict_types=1);

use App\Modules\Workflow\Application\Service\RuleVocabulary;
use App\Modules\Workflow\Application\Service\Webhook\WebhookDeliveries;
use App\Modules\Workflow\Application\Service\Webhook\WebhookEndpoints;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Outbound webhooks (ADR 0048).
 *
 * ADR 0014 kept `webhook` out of the engine because it "lets customer-authored
 * data reach the internet from inside the queue". Most of these tests are
 * about the bound that made it admissible: a rule names a registered endpoint,
 * never a URL; an address is checked before it is stored AND before every
 * send; the body is signed; the receiver's answer is recorded and never
 * printed; a receiver that stays down is given up on out loud.
 *
 * Every address here is an IP literal — 93.184.216.34 is public and needs no
 * DNS, so no test depends on the network, and `Http::fake()` answers before
 * anything leaves the process. `preventStrayRequests()` makes a request this
 * file did not arrange a failure, not a call to the internet.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->admin = $this->loginAs('rina@acme.test');       // webhook.manage, workflow.manage
    $this->manager = $this->loginAs('ahmad@acme.test');    // neither
});

/**
 * Register one and hand back [id, secret].
 *
 * @return array{0: string, 1: string}
 */
function registerEndpoint(string $token, string $name = 'Receiver', string $url = 'https://93.184.216.34/hook'): array
{
    $data = test()->withToken($token)
        ->postJson('/api/v1/webhook-endpoints', ['name' => $name, 'url' => $url])
        ->assertStatus(201)
        ->json('data');

    return [(string) $data['id'], (string) $data['secret']];
}

/** Does this request carry a valid signature for this secret? */
function signedWith(Request $request, string $secret): bool
{
    $header = $request->header('X-WorkOS-Signature')[0] ?? '';

    if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $parts) !== 1) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $parts[1].'.'.$request->body(), $secret), $parts[2]);
}

it('registers an endpoint and shows its secret exactly once', function (): void {
    [$id, $secret] = registerEndpoint($this->admin);

    expect($secret)->toStartWith('whsec_');

    $listed = collect($this->withToken($this->admin)
        ->getJson('/api/v1/webhook-endpoints')
        ->assertOk()
        ->json('data'))
        ->firstWhere('id', $id);

    // Not in the list, under any name. The model hides the column and the
    // resource never names it; this asserts both held.
    expect($listed)->not->toHaveKey('secret')
        ->and($listed)->not->toHaveKey('secret_encrypted')
        ->and(json_encode($listed))->not->toContain($secret);

    // Encrypted at rest, not stored as typed.
    expect(DB::table('webhook_endpoints')->where('id', $id)->value('secret_encrypted'))
        ->not->toBe($secret);
});

it('records where data may go in the audit log by HOST, never by the whole address', function (): void {
    // A chat tool's incoming-webhook URL is a credential in its own right.
    [$id] = registerEndpoint($this->admin, 'Chat', 'https://93.184.216.34/services/T000/B000/sEcReTtOkEn');

    $metadata = (string) DB::table('audit_logs')
        ->where('event', 'webhook.endpoint_registered')
        ->where('target_id', $id)
        ->value('metadata');

    expect($metadata)->toContain('93.184.216.34')
        ->and($metadata)->not->toContain('sEcReTtOkEn');
});

it('refuses addresses inside the network it runs in, when they are registered', function (string $url): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/webhook-endpoints', ['name' => 'Nope', 'url' => $url])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'webhook.destination_not_allowed');
})->with([
    'the cloud metadata service' => 'https://169.254.169.254/latest/meta-data/',
    'loopback' => 'https://127.0.0.1/hook',
    'a private network' => 'https://10.0.0.5/hook',
    'shared address space' => 'https://100.64.0.1/hook',
    'plain http' => 'http://93.184.216.34/hook',
    'credentials in the address' => 'https://user:pass@93.184.216.34/hook',
]);

it('keeps the administration screen from anybody without webhook.manage', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/webhook-endpoints')
        ->assertForbidden();

    $this->withToken($this->manager)
        ->postJson('/api/v1/webhook-endpoints', ['name' => 'Mine', 'url' => 'https://93.184.216.34/'])
        ->assertForbidden();
});

it('tells the rule builder what each endpoint is CALLED, and nothing about where it is', function (): void {
    [$id] = registerEndpoint($this->admin, 'Ops channel');

    $offered = collect($this->withToken($this->admin)
        ->getJson('/api/v1/workflow-vocabulary')
        ->assertOk()
        ->json('data.webhook_endpoints'))
        ->firstWhere('id', $id);

    expect($offered)->toEqual(['id' => $id, 'name' => 'Ops channel', 'is_active' => true]);
});

it('sends a signed ping and records that it arrived', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('ok', 200)]);

    [$id, $secret] = registerEndpoint($this->admin);

    $delivery = $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/test")
        ->assertStatus(201)
        ->json('data');

    expect($delivery['status'])->toBe('delivered')
        ->and($delivery['attempts'])->toBe(1)
        ->and($delivery['last_status_code'])->toBe(200);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/hook'
        && $request->header('X-WorkOS-Event')[0] === 'ping'
        && $request->header('X-WorkOS-Delivery')[0] === $delivery['id']
        && signedWith($request, $secret));
});

it('schedules a retry when the receiver fails, and does not keep what it said', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('<h1>internal admin page</h1>', 500)]);

    [$id] = registerEndpoint($this->admin);

    $delivery = $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/test")
        ->assertStatus(201)
        ->json('data');

    // Still pending, with the next try a backoff away — and the synchronous
    // test queue ran the re-dispatched job at once, which found the row not
    // yet due and left it alone. That is the lease doing its job.
    expect($delivery['status'])->toBe('pending')
        ->and($delivery['attempts'])->toBe(1)
        ->and($delivery['last_status_code'])->toBe(500)
        ->and($delivery['last_error'])->not->toContain('internal admin page')
        ->and($delivery['next_attempt_at'])->not->toBeNull();

    Http::assertSentCount(1);
});

it('abandons a delivery after its last attempt, and switches off an endpoint that keeps abandoning', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 503)]);

    [$id] = registerEndpoint($this->admin);

    $delivery = $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/test")
        ->json('data');

    // One attempt left, due now; and the endpoint one abandonment from the
    // threshold. Arranged rather than waited for — the backoff is hours.
    DB::table('webhook_deliveries')->where('id', $delivery['id'])->update([
        'attempts' => WebhookDeliveries::MAX_ATTEMPTS - 1,
        'next_attempt_at' => now()->subMinute(),
    ]);
    DB::table('webhook_endpoints')->where('id', $id)->update([
        'failure_count' => WebhookDeliveries::ENDPOINT_FAILURE_THRESHOLD - 1,
    ]);

    expect(app(WebhookDeliveries::class)->attempt($delivery['id']))->toBeNull();

    $row = DB::table('webhook_deliveries')->where('id', $delivery['id'])->first();
    $endpoint = DB::table('webhook_endpoints')->where('id', $id)->first();

    expect($row->status)->toBe('abandoned')
        ->and($row->next_attempt_at)->toBeNull()
        ->and($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toContain('abandoned');
});

it('sends nothing to a switched-off endpoint, and says so on the delivery', function (): void {
    Http::fake();

    [$id] = registerEndpoint($this->admin);

    $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/active", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $delivery = $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/test")
        ->json('data');

    expect($delivery['status'])->toBe('refused')
        ->and($delivery['last_error'])->toContain('switched off');

    Http::assertNothingSent();
});

it('checks the address again before every send, not only when it was saved', function (): void {
    Http::fake();

    [$id] = registerEndpoint($this->admin);

    // The row changed underneath the form — the shape of a name that resolved
    // publicly on Monday and privately on Tuesday.
    DB::table('webhook_endpoints')->where('id', $id)->update(['url' => 'https://10.0.0.5/hook']);

    $delivery = $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/test")
        ->json('data');

    expect($delivery['status'])->toBe('refused')
        ->and($delivery['attempts'])->toBe(1);

    Http::assertNothingSent();
});

it('sends what a rule saw when the rule matches, signed, as the vocabulary declares it', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);

    [$endpointId, $secret] = registerEndpoint($this->admin);

    $this->withToken($this->admin)->postJson('/api/v1/workflow-rules', [
        'name' => 'Tell the receiver about new work',
        'trigger' => 'work_item.created',
        'actions' => [['type' => 'webhook', 'with' => ['endpoint_id' => $endpointId]]],
    ])->assertStatus(201);

    $reference = $this->withToken($this->admin)
        ->postJson('/api/v1/work-items', ['title' => 'Something for the receiver'])
        ->assertStatus(201)
        ->json('data.reference');

    Http::assertSent(function (Request $request) use ($reference, $secret): bool {
        if (($request->header('X-WorkOS-Event')[0] ?? null) !== 'workflow.rule_matched') {
            return false;
        }

        $data = $request->data()['data'];

        // Facts outside the declared vocabulary never leave the product.
        $undeclared = array_diff(array_keys($data['facts']), array_keys(RuleVocabulary::FIELDS));

        return $data['subject']['reference'] === $reference
            && $data['facts']['title'] === 'Something for the receiver'
            && $undeclared === []
            && signedWith($request, $secret);
    });
});

it('refuses a rule that sends to an endpoint that does not exist', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/workflow-rules', [
        'name' => 'Sends nowhere',
        'trigger' => 'work_item.created',
        'actions' => [['type' => 'webhook', 'with' => ['endpoint_id' => '01900000-0000-7000-8000-00000000dead']]],
    ])->assertStatus(422);
});

it('refuses to delete an endpoint a rule still sends to, and names the rule', function (): void {
    [$id] = registerEndpoint($this->admin);

    $this->withToken($this->admin)->postJson('/api/v1/workflow-rules', [
        'name' => 'Still sending',
        'trigger' => 'work_item.created',
        'actions' => [['type' => 'webhook', 'with' => ['endpoint_id' => $id]]],
    ])->assertStatus(201);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/webhook-endpoints/{$id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'webhook.endpoint_in_use')
        ->assertJsonPath('error.details.rules', ['Still sending']);

    [$unused] = registerEndpoint($this->admin, 'Unused');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/webhook-endpoints/{$unused}")
        ->assertNoContent();
});

it('records one delivery for one change, however many times it is enqueued', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);

    [$id] = registerEndpoint($this->admin);

    $endpoint = app(WebhookEndpoints::class)->find($id);
    $deliveries = app(WebhookDeliveries::class);

    $first = $deliveries->enqueue($endpoint, 'workflow.rule_matched', ['n' => 1], 'same-change');
    $second = $deliveries->enqueue($endpoint, 'workflow.rule_matched', ['n' => 1], 'same-change');

    expect($second->id)->toBe($first->id)
        ->and(DB::table('webhook_deliveries')->where('endpoint_id', $id)->count())->toBe(1);

    Http::assertSentCount(1);
});

it('rotates a secret so the old one no longer signs', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 200)]);

    [$id, $old] = registerEndpoint($this->admin);

    $new = (string) $this->withToken($this->admin)
        ->postJson("/api/v1/webhook-endpoints/{$id}/secret")
        ->assertOk()
        ->json('data.secret');

    expect($new)->not->toBe($old);

    $this->withToken($this->admin)->postJson("/api/v1/webhook-endpoints/{$id}/test")->assertStatus(201);

    Http::assertSent(fn (Request $request): bool => signedWith($request, $new) && ! signedWith($request, $old));
});
