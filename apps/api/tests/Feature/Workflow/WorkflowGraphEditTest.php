<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Editing a graph that 180 work items are already moving through (ADR 0015).
 *
 * Every test here is a refusal, and that is the shape of the feature: the edits
 * worth making are dull, and the edits that need a decision are the ones that
 * would strand work or quietly rewrite what a finished quarter counted. A
 * builder that performed those would be reported as a data-loss bug weeks
 * later, by somebody who could not say what changed.
 *
 * Ids are written out rather than named by constant: Pest loads every test file
 * into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');       // workflow.manage
    $this->employee = $this->loginAs('sarah@acme.test');   // workflow.view only

    $this->workflow = '01900001-0000-7000-8000-000000000001';
    $this->state = [
        'backlog' => '01900002-0000-7000-8000-000000000001',
        'in_progress' => '01900002-0000-7000-8000-000000000003',
        'in_review' => '01900002-0000-7000-8000-000000000004',
        'completed' => '01900002-0000-7000-8000-000000000006',
    ];
});

it('adds a state that nothing yet leads to, and says as much by leaving it unwired', function (): void {
    $state = $this->withToken($this->admin)
        ->postJson("/api/v1/workflows/{$this->workflow}/states", [
            'key' => 'awaiting_client',
            'label' => 'Awaiting client',
            'category' => 'blocked',
        ])
        ->assertStatus(201)
        ->json('data');

    expect($state['category'])->toBe('blocked')
        ->and($state['is_initial'])->toBeFalse();

    // Unreachable until somebody draws a move into it — which the catalogue
    // screen reports, rather than this endpoint guessing at which edge was
    // meant.
    $edges = DB::table('workflow_transitions')->where('to_state_id', $state['id'])->count();

    expect($edges)->toBe(0);
});

it('refuses a category this product does not count', function (): void {
    $this->withToken($this->admin)
        ->postJson("/api/v1/workflows/{$this->workflow}/states", [
            'key' => 'pondering',
            'label' => 'Pondering',
            'category' => 'pondering',
        ])
        ->assertStatus(422);
});

it('renames a state, because the label is the customer\'s word', function (): void {
    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflows/{$this->workflow}/states/{$this->state['in_review']}", [
            'label' => 'QA Gate',
        ])
        ->assertOk()
        ->assertJsonPath('data.label', 'QA Gate')
        // The key is untouched, which is what keeps every rule matching on
        // `to_state_key` working through a rename.
        ->assertJsonPath('data.key', 'in_review');
});

it('refuses to change a state\'s category', function (): void {
    // Not a future change: every report already counted this work by category,
    // so the edit would rewrite a finished quarter with no record that the
    // meaning moved.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflows/{$this->workflow}/states/{$this->state['in_review']}", [
            'category' => 'in_progress',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'workflow.graph_edit_refused')
        ->assertJsonPath('error.details.refusal', 'category_is_load_bearing');
});

it('refuses to rename the key rules match on', function (): void {
    $this->withToken($this->admin)
        ->patchJson("/api/v1/workflows/{$this->workflow}/states/{$this->state['in_review']}", [
            'key' => 'qa_gate',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'key_is_matched_by_rules');
});

it('refuses to remove a state that work is sitting in', function (): void {
    $this->withToken($this->admin)
        ->deleteJson("/api/v1/workflows/{$this->workflow}/states/{$this->state['in_progress']}")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'state_holds_work');
});

it('refuses to remove a state whose moves would cascade away with it', function (): void {
    // The foreign key WOULD delete them, which is the argument for refusing:
    // the graph left behind is not the one the person looked at before
    // pressing the button.
    $empty = $this->withToken($this->admin)
        ->postJson("/api/v1/workflows/{$this->workflow}/states", [
            'key' => 'on_hold_external',
            'label' => 'On hold (external)',
            'category' => 'blocked',
        ])
        ->json('data.id');

    $this->withToken($this->admin)
        ->postJson("/api/v1/workflows/{$this->workflow}/transitions", [
            'from_state_id' => $this->state['backlog'],
            'to_state_id' => $empty,
            'label' => 'Park it',
        ])
        ->assertStatus(201);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/workflows/{$this->workflow}/states/{$empty}")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'state_has_transitions');
});

it('refuses a move that already exists', function (): void {
    $this->withToken($this->admin)
        ->postJson("/api/v1/workflows/{$this->workflow}/transitions", [
            'from_state_id' => $this->state['in_review'],
            'to_state_id' => $this->state['in_progress'],
            'label' => 'Send it back again',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'duplicate_transition');
});

it('refuses to remove the last way out of a state holding work', function (): void {
    $holding = DB::table('work_items')
        ->where('workflow_state_id', $this->state['in_progress'])
        ->count();

    if ($holding === 0) {
        $this->markTestSkipped('The seed has nothing in progress to strand.');
    }

    // The from-anywhere moves count as ways out — Blocked and Cancelled are
    // reachable from everywhere — so while they exist this removal is safe and
    // must be ALLOWED. Over-asking is not a safe direction for a guard: a check
    // that refused here would be refusing a correct edit.
    $wildcards = DB::table('workflow_transitions')
        ->where('workflow_id', $this->workflow)
        ->whereNull('from_state_id')
        ->pluck('id');

    $outOfProgress = DB::table('workflow_transitions')
        ->where('workflow_id', $this->workflow)
        ->where('from_state_id', $this->state['in_progress'])
        ->pluck('id');

    $last = $outOfProgress->pop();

    foreach ($outOfProgress as $id) {
        $this->withToken($this->admin)
            ->deleteJson("/api/v1/workflows/{$this->workflow}/transitions/{$id}")
            ->assertNoContent();
    }

    foreach ($wildcards as $id) {
        $this->withToken($this->admin)
            ->deleteJson("/api/v1/workflows/{$this->workflow}/transitions/{$id}")
            ->assertNoContent();
    }

    // Now it is the only move out of a state holding work: removing it would
    // leave those items unable to be advanced, cancelled or unblocked by
    // anybody, and the only symptom would be somebody reporting that their
    // buttons are gone.
    $this->withToken($this->admin)
        ->deleteJson("/api/v1/workflows/{$this->workflow}/transitions/{$last}")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'would_strand_work');
});

it('denies the whole graph to somebody who may only read it', function (): void {
    $this->withToken($this->employee)
        ->postJson("/api/v1/workflows/{$this->workflow}/states", [
            'key' => 'nope',
            'label' => 'Nope',
            'category' => 'todo',
        ])
        ->assertForbidden();

    $this->withToken($this->employee)
        ->deleteJson("/api/v1/workflows/{$this->workflow}/states/{$this->state['completed']}")
        ->assertForbidden();
});
