<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * What `POST /work-items` may not be used to reach.
 *
 * The request only checked that a project or a parent EXISTED in the
 * organization. Sarah — an employee, not a member of the private FIN project,
 * without `work_item.assign` — could put an item on FIN's board, read FIN's key
 * and name off the response, and assign the item to anybody.
 */
const CW_FIN = '01900003-0000-7000-8000-000000000005';
const CW_ENG = '01900003-0000-7000-8000-000000000001';
const CW_SARAH = '01900000-0000-7000-8000-000000000203';
const CW_BUDI = '01900000-0000-7000-8000-000000000206';

beforeEach(function (): void {
    $this->sarah = $this->loginAs('sarah@acme.test');
});

it('does not create work in a project the caller cannot see', function (): void {
    $before = DB::table('work_items')->where('project_id', CW_FIN)->count();

    $this->withToken($this->sarah)
        ->postJson('/api/v1/work-items', ['title' => 'Slipped in', 'project_id' => CW_FIN])
        ->assertNotFound();

    expect(DB::table('work_items')->where('project_id', CW_FIN)->count())->toBe($before);
});

it('does not create a subtask under work the caller cannot see', function (): void {
    // One Sarah has no other way to see: not assigned to her (an assignee sees
    // their own work, private project or not), not created or watched by her.
    $parent = DB::table('work_items as w')
        ->where('w.project_id', CW_FIN)
        ->where('w.created_by_membership_id', '!=', CW_SARAH)
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('work_item_assignments as a')
            ->whereColumn('a.work_item_id', 'w.id')->where('a.membership_id', CW_SARAH))
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('work_item_watchers as x')
            ->whereColumn('x.work_item_id', 'w.id')->where('x.membership_id', CW_SARAH))
        ->value('w.id');

    expect($parent)->not->toBeNull();

    $this->withToken($this->sarah)
        ->postJson('/api/v1/work-items', ['title' => 'Slipped in below', 'parent_id' => $parent])
        ->assertNotFound();
});

it('lets an employee take work but not hand it to somebody else', function (): void {
    $this->withToken($this->sarah)
        ->postJson('/api/v1/work-items', ['title' => 'For Budi', 'project_id' => CW_ENG, 'assignee_id' => CW_BUDI])
        ->assertForbidden();

    $this->withToken($this->sarah)
        ->postJson('/api/v1/work-items', ['title' => 'For me', 'project_id' => CW_ENG, 'assignee_id' => CW_SARAH])
        ->assertCreated();

    // A manager holds work_item.assign and still can.
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->postJson('/api/v1/work-items', ['title' => 'For Budi, from Ahmad', 'project_id' => CW_ENG, 'assignee_id' => CW_BUDI])
        ->assertCreated();
});
