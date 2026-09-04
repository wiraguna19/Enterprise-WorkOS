<?php

declare(strict_types=1);

use App\Modules\Governance\Application\Service\ActivityLogger;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;
use Illuminate\Support\Facades\DB;

/**
 * The timeline, read back through the API (docs/02 §8).
 *
 * `ActivityLogger` has written to `activity_logs` since Phase 2 and nothing
 * read it: `activity.view` was granted to every role and had no endpoint behind
 * it. The only assertions on the trail were made against the table from inside
 * the suite — which proves the writer works and proves nothing about whether
 * anyone can ever see what it wrote.
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('shows what happened to a work item, newest first', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $this->withToken($this->manager)->postJson('/api/v1/work-items/ENG-144/transition', [
        'to_state_id' => '01900002-0000-7000-8000-000000000004',   // In Review
    ])->assertOk();

    $timeline = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144/activity')
        ->assertOk()
        ->json('data');

    expect($timeline)->not->toBeEmpty();

    $verbs = collect($timeline)->flatMap(fn (array $group) => collect($group['entries'])->pluck('verb'))->all();

    // Read from `users` rather than written in here as a literal. The field is
    // a SNAPSHOT — its whole purpose is that history keeps reading correctly
    // after the person is deactivated or renamed — so a test that hardcodes
    // today's spelling checks the seed, not the behaviour. (It also spared this
    // one: the name in the seed is Ahmad Rizal, not the Rizki I assumed.)
    $actor = DB::table('users')->where('email', 'ahmad@acme.test')->value('name');

    expect($verbs)->toContain('status_changed')
        ->and($timeline[0]['actor'])->toBe($actor)
        ->and($item->fresh()->state_category)->toBe('in_review');

    // The destination by the name the workflow gives it, not by its category.
    // The log recorded only the category until now, so the timeline could say
    // "moved it to in_review" — a vocabulary five states share, and not a
    // sentence anyone recognises about their own board.
    $move = collect($timeline)
        ->flatMap(fn (array $group) => $group['entries'])
        ->firstWhere('verb', 'status_changed');

    expect($move['changes']['label']['to'])->toBe('In Review')
        ->and($move['changes']['state']['to'])->toBe('in_review');
});

/**
 * One action, one row on the timeline.
 *
 * `ActivityLogger::grouped()` exists so that a single decision writing several
 * entries reads as one event with its parts, rather than as four separate
 * decisions a second apart. Nothing tested that the grouping survived being
 * read back, which is where a correlation id is easiest to lose.
 */
it('collapses entries written under one correlation into one event', function (): void {
    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $before = count($this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144/activity')->json('data'));

    app(ActivityLogger::class)->grouped(function () use ($item): void {
        $logger = app(ActivityLogger::class);

        $logger->record('work_item', (string) $item->getKey(), 'updated', ['title' => ['from' => 'a', 'to' => 'b']]);
        $logger->record('work_item', (string) $item->getKey(), 'moved', ['project' => ['from' => 'x', 'to' => 'y']]);
    });

    $timeline = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144/activity')
        ->assertOk()
        ->json('data');

    expect($timeline)->toHaveCount($before + 1)
        ->and($timeline[0]['entries'])->toHaveCount(2);
});

/**
 * The timeline is as private as its subject.
 *
 * The endpoint is in the Work module for exactly this reason: reading a
 * history is a visibility decision about the item, and Governance cannot see
 * work items. A 404 rather than a 403 — the same rule the item itself follows,
 * because a 403 confirms the reference is real (docs/05 §3).
 */
it('is refused to someone who cannot see the item', function (): void {
    $outsider = $this->loginAs('gil@globex.test');

    $this->withToken($outsider)
        ->getJson('/api/v1/work-items/ENG-144/activity')
        ->assertNotFound();
});
