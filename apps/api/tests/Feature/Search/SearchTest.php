<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The command palette's contract (docs/08 §5, docs/10 Phase 5 exit criteria).
 *
 * Half of these are negative. A search box is the easiest place in a product to
 * leak a record: it reaches across every table at once, and "no results" is the
 * only thing standing between a curious employee and a private project's
 * titles. Tenant scoping is not the control here — visibility is (docs/06 §2).
 */
beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
});

it('finds a work item by its reference, exactly as people paste it', function (): void {
    $results = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=ENG-142')
            ->assertOk()
            ->json('data')
    );

    $first = $results->first();

    expect($first['type'])->toBe('work_item')
        ->and($first['reference'])->toBe('ENG-142')
        // The reference is the strongest possible signal: someone who pastes it
        // knows precisely what they want, and anything above it is noise.
        ->and($first['matched_on'])->toBe('reference');
});

it('is case-insensitive about references, because chat is', function (): void {
    $references = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=eng-142')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->toContain('ENG-142');
});

it('finds work by words in its title', function (): void {
    $results = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=connection+pool')
            ->assertOk()
            ->json('data')
    );

    expect($results->pluck('reference'))->toContain('ENG-144')
        ->and($results->firstWhere('reference', 'ENG-144')['matched_on'])->toBe('title');
});

it('finds work by what was said about it, not only by what it is called', function (): void {
    // The search people actually run: they remember the conversation, not the
    // title. This is the Phase 5 exit criterion.
    DB::table('comments')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'commentable_type' => 'work_item',
        'commentable_id' => '01900014-0000-7000-8000-000000000001',   // ENG-142
        'author_membership_id' => '01900000-0000-7000-8000-000000000202',
        'body_markdown' => 'The zeppelin diagram explains why this deadlocks.',
        'body_html' => '<p>The zeppelin diagram explains why this deadlocks.</p>',
    ]);

    $results = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=zeppelin')
            ->assertOk()
            ->json('data')
    );

    $hit = $results->firstWhere('reference', 'ENG-142');

    expect($hit)->not->toBeNull()
        ->and($hit['matched_on'])->toBe('comment');
});

it('returns one row for an item discussed many times', function (): void {
    foreach (range(1, 3) as $i) {
        DB::table('comments')->insert([
            'id' => (string) new UuidV7,
            'organization_id' => '01900000-0000-7000-8000-0000000000ac',
            'commentable_type' => 'work_item',
            'commentable_id' => '01900014-0000-7000-8000-000000000001',
            'author_membership_id' => '01900000-0000-7000-8000-000000000202',
            'body_markdown' => "Zeppelin, again ({$i}).",
            'body_html' => "<p>Zeppelin, again ({$i}).</p>",
        ]);
    }

    $matches = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=zeppelin')
            ->assertOk()
            ->json('data')
    )->where('reference', 'ENG-142');

    expect($matches)->toHaveCount(1);
});

it('finds projects and people, not only work', function (): void {
    $types = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=platform&limit=25')
            ->assertOk()
            ->json('data')
    )->pluck('type');

    expect($types)->toContain('project');

    $people = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=sarah')
            ->assertOk()
            ->json('data')
    );

    expect($people->pluck('type'))->toContain('person')
        ->and($people->firstWhere('type', 'person')['title'])->toBe('Sarah Chen');
});

it('narrows to the types the caller asked for', function (): void {
    $types = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=platform&types=project')
            ->assertOk()
            ->json('data')
    )->pluck('type')->unique();

    expect($types->all())->toBe(['project']);
});

it('refuses an unknown type instead of ignoring it', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/search?q=platform&types=project,unicorn')
        ->assertStatus(422);
});

it('refuses a query too short to mean anything', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/search?q=a')
        ->assertStatus(422);
});

it('never returns another tenant\'s work', function (): void {
    $references = collect(
        $this->withToken($this->manager)
            ->getJson('/api/v1/search?q=GBX-1')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->not->toContain('GBX-1');
});

it('does not show a private project to someone who cannot open it', function (): void {
    // Budi is not a member of FIN. If search can name it, search has become a
    // way to discover records the rest of the API refuses to serve.
    $results = collect(
        $this->withToken($this->loginAs('budi@acme.test'))
            ->getJson('/api/v1/search?q=Budget+Planning&limit=25')
            ->assertOk()
            ->json('data')
    );

    expect($results->where('type', 'project')->pluck('title'))
        ->not->toContain('Budget Planning FY27');
});

it('does not leak a private project\'s work through a comment match', function (): void {
    $finItem = DB::table('work_items')
        ->where('project_id', '01900003-0000-7000-8000-000000000005')
        ->whereNotExists(fn ($sub) => $sub
            ->from('work_item_assignments')
            ->whereColumn('work_item_assignments.work_item_id', 'work_items.id')
            ->where('membership_id', '01900000-0000-7000-8000-000000000206'))
        ->first();

    DB::table('comments')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'commentable_type' => 'work_item',
        'commentable_id' => $finItem->id,
        'author_membership_id' => '01900000-0000-7000-8000-000000000201',
        'body_markdown' => 'Absolutely confidential rutabaga forecast.',
        'body_html' => '<p>Absolutely confidential rutabaga forecast.</p>',
    ]);

    $references = collect(
        $this->withToken($this->loginAs('budi@acme.test'))
            ->getJson('/api/v1/search?q=rutabaga')
            ->assertOk()
            ->json('data')
    )->pluck('reference');

    expect($references)->not->toContain($finItem->reference);
});
