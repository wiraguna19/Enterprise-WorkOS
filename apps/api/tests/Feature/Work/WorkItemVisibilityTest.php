<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The security boundary (docs/06 §2).
 *
 * Every one of these is a NEGATIVE test. A visibility suite that only asserts
 * what people CAN see proves nothing — the failure mode is always the row that
 * should have been hidden.
 */
it('never shows another tenant\'s work', function (): void {
    $references = collect(
        $this->withToken($this->loginAs('rina@acme.test'))
            ->getJson('/api/v1/work-items?limit=100')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->not->toContain('GBX-1');
});

it('returns 404, not 403, for another tenant\'s work item', function (): void {
    // A 403 would confirm the item exists (docs/05 §3).
    $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/work-items/GBX-1')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'resource.not_found');
});

it('hides a private project from a non-member', function (): void {
    $keys = collect(
        $this->withToken($this->loginAs('sarah@acme.test'))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->json('data')
    )->pluck('key');

    expect($keys)->not->toContain('FIN');
});

it('shows the private project to its owner', function (): void {
    $keys = collect(
        $this->withToken($this->loginAs('rina@acme.test'))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->json('data')
    )->pluck('key');

    expect($keys)->toContain('FIN');
});

it('keeps work visible to someone it was reassigned away from', function (): void {
    // David handed ENG-142 to Sarah. Losing sight of it would make the history
    // feature useless in practice.
    $this->withToken($this->loginAs('david@acme.test'))
        ->getJson('/api/v1/work-items/ENG-142')
        ->assertOk();
});

it('shows a contractor nothing they were not given', function (): void {
    $items = $this->withToken($this->loginAs('tono@acme.test'))
        ->getJson('/api/v1/work-items?limit=100')
        ->assertOk()
        ->json('data');

    expect($items)->toBe([]);
});

it('lets a manager see their report\'s work through the reporting line', function (): void {
    $references = collect(
        $this->withToken($this->loginAs('ahmad@acme.test'))
            ->getJson('/api/v1/work-items?filter[assignee_id]=01900000-0000-7000-8000-000000000203&limit=100')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->not->toBeEmpty();
});

it('applies visibility before filters, so a filter cannot widen it', function (): void {
    // Sarah is not a member of the private FIN project, but the seed assigns
    // her a handful of items inside it — and assigned work stays visible
    // wherever it lives (docs/06 §2, clause 2). So the honest assertion is not
    // "she sees nothing": it is that filtering BY that project hands her only
    // the rows she was already entitled to, never the project's other work.
    $items = $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/work-items?filter[project_id]=01900003-0000-7000-8000-000000000005&limit=100')
        ->assertOk()
        ->json('data');

    $sarah = '01900000-0000-7000-8000-000000000203';

    foreach ($items as $item) {
        expect(collect($item['assignees'])->pluck('membership_id')->all())
            ->toContain($sarah);
    }

    $inProject = DB::table('work_items')
        ->where('project_id', '01900003-0000-7000-8000-000000000005')
        ->whereNull('deleted_at')
        ->count();

    expect(count($items))->toBeLessThan(
        $inProject,
        'the filter returned the whole private project, which is the leak this test exists for',
    );
});

it('rejects an unknown filter key instead of ignoring it', function (): void {
    // Silent ignoring is how a client ships a broken filter that nobody notices
    // for a month (docs/05 §4).
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->getJson('/api/v1/work-items?filter[type]=nonsense')
        ->assertStatus(422);
});

it('keeps project-less work private to the people involved in it', function (): void {
    // ADR 0004. Work with no project is a person's own note until they put it
    // in a project; nobody reaches it through a permission alone.
    $sarah = '01900000-0000-7000-8000-000000000203';

    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'reference' => 'ENG-9001',
        'title' => 'Draft nobody has been told about yet',
        'project_id' => null,
        'workflow_id' => '01900001-0000-7000-8000-000000000001',
        'workflow_state_id' => '01900002-0000-7000-8000-000000000002',
        'state_category' => 'todo',
        'created_by_membership_id' => $sarah,
    ]);

    // Rina is the Organization Admin: she holds project.view_all and every
    // other permission in the catalogue. That widens clause 1, and clause 1
    // needs a project.
    $adminSees = collect(
        $this->withToken($this->loginAs('rina@acme.test'))
            ->getJson('/api/v1/work-items?limit=100')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($adminSees)->not->toContain('ENG-9001');

    $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/work-items/ENG-9001')
        ->assertNotFound();

    // And it is not lost — its author still has it.
    $authorSees = collect(
        $this->withToken($this->loginAs('sarah@acme.test'))
            ->getJson('/api/v1/work-items?limit=100')
            ->json('data')
    )->pluck('reference');

    expect($authorSees)->toContain('ENG-9001');
});

it('does not surface project-less work through search or the calendar', function (): void {
    // The two endpoints that reach across every record in the tenant. Search
    // and the calendar each apply the visibility rule rather than reimplement
    // it, and this is the test that says so out loud (ADR 0004).
    $sarah = '01900000-0000-7000-8000-000000000203';

    DB::table('work_items')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'reference' => 'ENG-9002',
        'title' => 'Unshared aardvark investigation',
        'project_id' => null,
        'workflow_id' => '01900001-0000-7000-8000-000000000001',
        'workflow_state_id' => '01900002-0000-7000-8000-000000000002',
        'state_category' => 'todo',
        'created_by_membership_id' => $sarah,
        'due_at' => now()->addDays(3),
    ]);

    $admin = $this->loginAs('rina@acme.test');

    $found = collect(
        $this->withToken($admin)->getJson('/api/v1/search?q=aardvark')->assertOk()->json('data')
    )->pluck('reference');

    expect($found)->not->toContain('ENG-9002');

    $dated = collect(
        $this->withToken($admin)->getJson('/api/v1/calendar?sources=work')->assertOk()->json('data')
    )->pluck('reference');

    expect($dated)->not->toContain('ENG-9002');
});
