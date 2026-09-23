<?php

declare(strict_types=1);

/**
 * The filter whitelist, and the custom-field half of it (docs/05 §4, ADR 0038).
 *
 * `ListWorkItemsRequest` has said since Phase 3 that "an unknown key is a 422,
 * never silently ignored — silent ignoring is how a client ships a broken
 * filter that nobody notices for a month". Nothing implemented it. A key with
 * no rule was not refused; it was never mentioned, because every rule is
 * `sometimes`.
 *
 * So these tests are mostly about REFUSALS that did not previously happen. The
 * one that matters most is the typo: `assignee` is one letter short of
 * `assignee_id`, and it used to return everybody's work to a client that
 * believed it had asked for one person's.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('refuses a filter key it does not answer, and names it', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[assignee]=someone')
        ->assertStatus(422)
        // The KEY in the message. "Invalid filter" helps nobody, and the
        // commonest cause of this is a typo one letter long.
        ->assertJsonFragment(['filter' => ['Unknown filter "assignee". Allowed: project_id, milestone_id, parent_id, type, priority, state_category, state_id, assignee_id, team_id, overdue, unassigned, tag, or cf_<key> for a custom field.']]);
});

it('still answers every filter it always has', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[state_category]=todo,in_progress&filter[overdue]=1&limit=5')
        ->assertOk();
});

it('refuses a sort it cannot perform instead of quietly ignoring it', function (): void {
    // This used to succeed, in the default order, with no sign that the sort
    // had been dropped — the same silence, one field over.
    $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?sort=titel')
        ->assertStatus(422);

    $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?sort=-due_at,reference')
        ->assertOk();
});

it('filters by a custom field the organization declared', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'client', 'label' => 'Client', 'type' => 'text',
        ])->assertStatus(201);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['client' => 'Acme']])
        ->assertOk();

    $matching = collect($this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[cf_client]=Acme&limit=100')
        ->assertOk()
        ->json('data'))
        ->pluck('reference');

    expect($matching)->toContain('ENG-144');

    // And the negative, which is the half that catches a WHERE that never
    // narrowed anything: a filter matching nothing must return nothing, not
    // everything.
    $none = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[cf_client]=Globex&limit=100')
        ->assertOk()
        ->json('data');

    expect($none)->toBe([]);
});

it('refuses a custom field this organization never declared', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'client', 'label' => 'Client', 'type' => 'text',
        ])->assertStatus(201);

    $message = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[cf_nonsense]=x')
        ->assertStatus(422)
        ->json('error.details.filter.0');

    // The key that was refused, and the custom keys that DO exist. The second
    // half is what makes this message worth reading: somebody who mistyped
    // `cf_client` can see the spelling that works.
    expect($message)->toContain('cf_nonsense')
        ->toContain('cf_client');
});

it('refuses a custom filter given more than one value', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'client', 'label' => 'Client', 'type' => 'text',
        ])->assertStatus(201);

    // Stringifying an array yields "Array", which matches nothing and reads as
    // an empty result rather than as the refusal it is.
    $this->withToken($this->manager)
        ->getJson('/api/v1/work-items?filter[cf_client][]=Acme&filter[cf_client][]=Globex')
        ->assertStatus(422);
});

/**
 * The same promise, on the three endpoints that never had it (ADR 0039).
 *
 * `/people`, `/teams` and `/projects` read `filter.*` straight off the request
 * with nothing validating anything — so an unknown key was ignored, and a
 * malformed one reached Postgres. The 500 is the part worth a test: a bad query
 * string is the caller's mistake, and answering 500 sends them to read server
 * logs for it.
 */
it('refuses an unknown filter on people, teams and projects', function (): void {
    foreach (['people', 'teams', 'projects'] as $collection) {
        $this->withToken($this->admin)
            ->getJson("/api/v1/{$collection}?filter[nonsense]=x")
            ->assertStatus(422);
    }
});

it('answers 422, not 500, when a uuid filter is not a uuid', function (): void {
    foreach (['people', 'teams'] as $collection) {
        $this->withToken($this->admin)
            ->getJson("/api/v1/{$collection}?filter[department_id]=banana")
            ->assertStatus(422);
    }
});

it('refuses a status outside the list the database enforces', function (): void {
    // `activ` matched nothing and rendered as an organization with no
    // projects — a wrong answer that looks computed, which nobody reports.
    $this->withToken($this->admin)
        ->getJson('/api/v1/projects?filter[status]=activ')
        ->assertStatus(422);

    $this->withToken($this->admin)
        ->getJson('/api/v1/projects?filter[status]=active')
        ->assertOk();

    $this->withToken($this->admin)
        ->getJson('/api/v1/people?filter[status]=suspended')
        ->assertOk();
});
