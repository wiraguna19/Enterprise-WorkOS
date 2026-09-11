<?php

declare(strict_types=1);

/**
 * The workflow catalogue, as an administrator reads it (docs/02 §7).
 *
 * `GET /workflows` existed from Phase 4 and nothing called it for three
 * phases, so nothing ever asked what it OMITTED: the states, and not one of
 * the edges between them. A list of statuses is not a workflow — which move is
 * legal is a property of the edges alone, and it was unreachable and untested
 * at the same time, as those two absences always are.
 *
 * Ids are written out rather than named by constant: Pest loads every test file
 * into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');       // Org Admin
    $this->employee = $this->loginAs('sarah@acme.test');   // workflow.view, not workflow.manage

    $this->defaultWorkflow = '01900001-0000-7000-8000-000000000001';
    $this->state = [
        'in_progress' => '01900002-0000-7000-8000-000000000003',
        'in_review' => '01900002-0000-7000-8000-000000000004',
        'blocked' => '01900002-0000-7000-8000-000000000007',
    ];
});

it('sends the edges, not only the states', function (): void {
    $workflows = $this->withToken($this->admin)
        ->getJson('/api/v1/workflows')
        ->assertOk()
        ->json('data');

    $default = collect($workflows)->firstWhere('id', $this->defaultWorkflow);

    $submit = collect($default['transitions'])
        ->firstWhere('to_state_id', $this->state['in_review']);

    expect($submit)->not->toBeNull()
        ->and($submit['from_state_id'])->toBe($this->state['in_progress'])
        ->and($submit['label'])->toBe('Submit for review')
        // The screen draws this differently: an edge that stops to ask for a
        // reason is a different promise from one that does not.
        ->and($submit['requires_comment'])->toBeTrue();
});

it('keeps a from-anywhere edge from-anywhere', function (): void {
    // Expanding the wildcard into one edge per state would be twelve rows that
    // say something the graph does not: that somebody enumerated them. The fan
    // -out IS the fact, and only a null can carry it.
    $workflows = $this->withToken($this->admin)
        ->getJson('/api/v1/workflows')
        ->assertOk()
        ->json('data');

    $blocked = collect(collect($workflows)->firstWhere('id', $this->defaultWorkflow)['transitions'])
        ->firstWhere('to_state_id', $this->state['blocked']);

    expect($blocked)->not->toBeNull()
        ->and($blocked['from_state_id'])->toBeNull();
});

it('says an edge is guarded without saying what the guard is', function (): void {
    // The guard is a predicate over facts this endpoint does not have — an item
    // and an actor. Sending it beside a graph invites reading it as a promise
    // about a move the reader has not made yet; `available-transitions` is the
    // endpoint that can answer that, per item and per actor.
    $transitions = collect($this->withToken($this->admin)
        ->getJson('/api/v1/workflows')
        ->assertOk()
        ->json('data'))
        ->firstWhere('id', $this->defaultWorkflow)['transitions'];

    $approve = collect($transitions)->firstWhere('label', 'Approve');
    $complete = collect($transitions)->firstWhere('label', 'Complete');

    expect($approve['is_guarded'])->toBeTrue()
        ->and($complete['is_guarded'])->toBeFalse()
        ->and($approve)->not->toHaveKey('guard');
});

it('shows a rule its health, so a rule that has been failing silently is visible', function (): void {
    $rules = $this->withToken($this->admin)
        ->getJson('/api/v1/workflow-rules')
        ->assertOk()
        ->json('data');

    $broken = collect($rules)->firstWhere('id', '01900021-0000-7000-8000-000000000005');

    expect($broken['health']['healthy'])->toBeFalse()
        ->and($broken['health']['failure_count'])->toBe(5)
        ->and($broken['health']['disabled_reason'])->not->toBeEmpty();
});

it('refuses the run log to somebody who may only read the rules', function (): void {
    // Two permissions on purpose: what the rules ARE is configuration anyone
    // working inside them benefits from seeing; what they DID names subjects
    // the reader may not be entitled to.
    $this->withToken($this->employee)
        ->getJson('/api/v1/workflow-rules')
        ->assertOk();

    $this->withToken($this->employee)
        ->getJson('/api/v1/workflow-rules/01900021-0000-7000-8000-000000000001/runs')
        ->assertForbidden();
});
