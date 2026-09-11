<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Authoring a rule (docs/02 §7, docs/10 Phase 7).
 *
 * The engine is TOTAL by design — a malformed predicate is false, never an
 * exception — which is right for a queued job and wrong for a form: a rule
 * saved with a misspelt field never matches and never errors, and nothing in
 * the product would ever say so. Every refusal below exists to move that
 * silence to the door, while the person who typed it is still looking at it.
 *
 * Ids are written out rather than named by constant: Pest loads every test file
 * into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');       // workflow.manage
    $this->employee = $this->loginAs('sarah@acme.test');   // workflow.view only

    $this->disabledRule = '01900021-0000-7000-8000-000000000005';

    $this->valid = [
        'name' => 'Tell the reviewer about urgent work',
        'description' => 'When urgent work enters review, notify its reviewers.',
        'trigger' => 'work_item.status_changed',
        'conditions' => ['all' => [
            ['field' => 'to_state_key', 'op' => 'eq', 'value' => 'in_review'],
            ['field' => 'priority', 'op' => 'in', 'value' => ['high', 'urgent']],
        ]],
        'actions' => [
            ['type' => 'notify', 'with' => ['to' => ['reviewer'], 'notification_type' => 'workflow.rule']],
        ],
    ];
});

it('stores a rule the engine can actually run', function (): void {
    $id = $this->withToken($this->admin)
        ->postJson('/api/v1/workflow-rules', $this->valid)
        ->assertStatus(201)
        ->json('data.id');

    // Read back through the query builder rather than the model: the tenant
    // scope throws without a request's context, and this assertion is about
    // what landed in the row, not about who may see it.
    $row = DB::table('workflow_rules')->where('id', $id)->first();

    // `toEqual`, not `toBe`: the columns are `jsonb`, which stores a parsed
    // document rather than the text that arrived and hands the keys back
    // ordered by length then alphabetically — `op` before `field` before
    // `value`. Identity would be asserting the key order Postgres chose, which
    // is not a property of this feature and would fail on a document nothing
    // is wrong with.
    expect($row->trigger)->toBe('work_item.status_changed')
        ->and($row->is_active)->toBeTrue()
        ->and(json_decode((string) $row->conditions, true))->toEqual($this->valid['conditions'])
        ->and(json_decode((string) $row->actions, true))->toEqual($this->valid['actions']);
});

it('refuses a fact the engine never supplies, and names it', function (): void {
    // The whole point of validating here. `priorty` would be stored happily,
    // evaluate to false forever, and log a clean "skipped" every time.
    $response = $this->withToken($this->admin)
        ->postJson('/api/v1/workflow-rules', [
            ...$this->valid,
            'conditions' => ['all' => [['field' => 'priorty', 'op' => 'eq', 'value' => 'high']]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    expect(json_encode($response->json('error.details')))->toContain('priorty');
});

it('reports every refusal in the predicate, not the first', function (): void {
    // A form somebody is composing should not become a queue of round trips.
    $response = $this->withToken($this->admin)
        ->postJson('/api/v1/workflow-rules', [
            ...$this->valid,
            'conditions' => ['all' => [
                ['field' => 'nonsense', 'op' => 'eq', 'value' => 'x'],
                ['field' => 'priority', 'op' => 'sounds_like', 'value' => 'high'],
            ]],
        ])
        ->assertStatus(422);

    expect($response->json('error.details.conditions'))->toHaveCount(2);
});

it('refuses an action the executor has no handler for', function (): void {
    // `webhook` is deliberately absent from ActionExecutor until it can be
    // bounded — a rule naming it would throw inside a queued job, hours later,
    // in a log nobody opened.
    $this->withToken($this->admin)
        ->postJson('/api/v1/workflow-rules', [
            ...$this->valid,
            'actions' => [['type' => 'webhook', 'with' => ['url' => 'https://example.test']]],
        ])
        ->assertStatus(422);
});

it('lets an administrator switch a rule off without rewriting it', function (): void {
    $id = '01900021-0000-7000-8000-000000000001';
    $before = DB::table('workflow_rules')->where('id', $id)->first();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflow-rules/{$id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $after = DB::table('workflow_rules')->where('id', $id)->first();

    expect($after->is_active)->toBeFalse()
        // Only what was sent. A PATCH that rewrote the conditions with the
        // client's last read would silently revert an edit made a minute ago.
        ->and($after->conditions)->toBe($before->conditions)
        ->and($after->name)->toBe($before->name);
});

it('gives a rule switched back on a clean slate', function (): void {
    // The engine disables after five consecutive failures. Leaving the count
    // where it was puts a rule somebody has just fixed one failure away from
    // being disabled again, which reads as the fix not working.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflow-rules/{$this->disabledRule}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.health.healthy', true);

    $rule = DB::table('workflow_rules')->where('id', $this->disabledRule)->first();

    expect($rule->failure_count)->toBe(0)
        ->and($rule->disabled_reason)->toBeNull();
});

it('denies authoring to somebody who may only read the rules', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/workflow-rules', $this->valid)
        ->assertForbidden();

    $this->withToken($this->employee)
        ->patchJson("/api/v1/workflow-rules/{$this->disabledRule}", ['is_active' => true])
        ->assertForbidden();
});

it('cannot reach another organization\'s rule', function (): void {
    // Globex exists in the seed for exactly this: a leak is only visible when
    // there is something to leak. The tenant scope answers before the policy
    // does, so the honest status is 404.
    $globex = DB::table('workflow_rules')
        ->where('organization_id', '!=', '01900000-0000-7000-8000-0000000000ac')
        ->first();

    if ($globex === null) {
        $this->markTestSkipped('The seed carries no second-tenant rule to attempt.');
    }

    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflow-rules/{$globex->id}", ['is_active' => false])
        ->assertNotFound();
});
