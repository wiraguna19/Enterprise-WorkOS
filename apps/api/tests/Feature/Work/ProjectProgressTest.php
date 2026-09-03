<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The progress rollup, and the thing that matters most about it: that what it
 * caches equals what the health page computes live.
 *
 * Two implementations of one rule is the defect this codebase keeps finding, so
 * the agreement between them is asserted rather than assumed. The health page
 * does not read the cache — it is one project and can afford the query, while
 * the directory renders every project a person can see.
 */
const P_ORG = '01900000-0000-7000-8000-0000000000ac';
const P_WF = '01900001-0000-7000-8000-000000000001';
const P_S_TODO = '01900002-0000-7000-8000-000000000002';
const P_S_DONE = '01900002-0000-7000-8000-000000000006';
const P_S_CANCELLED = '01900002-0000-7000-8000-000000000008';
const P_OWNER = '01900000-0000-7000-8000-000000000202';

/** @return array{0: string, 1: string} the project's id and key */
function progressProject(): array
{
    $id = (string) new UuidV7;
    $key = 'PG'.random_int(100, 999);

    DB::table('projects')->insert([
        'id' => $id,
        'organization_id' => P_ORG,
        'key' => $key,
        'name' => 'Progress fixture',
        'workflow_id' => P_WF,
        'status' => 'active',
        'visibility' => 'internal',
        'owner_membership_id' => P_OWNER,
    ]);

    return [$id, $key];
}

function progressItem(string $projectId, string $category): void
{
    DB::table('work_items')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => P_ORG,
        'reference' => 'PRG-'.random_int(10000, 99999),
        'title' => 'Progress fixture item',
        'project_id' => $projectId,
        'workflow_id' => P_WF,
        'workflow_state_id' => match ($category) {
            'done' => P_S_DONE,
            'cancelled' => P_S_CANCELLED,
            default => P_S_TODO,
        },
        'state_category' => $category,
        'completed_at' => $category === 'done' ? now() : null,
        'created_by_membership_id' => P_OWNER,
    ]);
}

function cachedProgress(string $projectId): object
{
    /** @var object{progress_cache: string, progress_cached_at: string|null} $row */
    $row = DB::table('projects')
        ->select('progress_cache', 'progress_cached_at')
        ->where('id', $projectId)
        ->first();

    return $row;
}

it('caches the completed share of everything that is not cancelled', function (): void {
    [$id] = progressProject();

    progressItem($id, 'done');
    progressItem($id, 'todo');
    progressItem($id, 'todo');
    // Cancelled work is neither delivered nor remaining, so it leaves the
    // denominator entirely — otherwise abandoning work would move the bar.
    progressItem($id, 'cancelled');

    $this->artisan('work:roll-up-project-progress')->assertSuccessful();

    expect((float) cachedProgress($id)->progress_cache)->toBe(33.33);
});

it('agrees with the figure the health page computes live', function (): void {
    [$id, $key] = progressProject();

    progressItem($id, 'done');
    progressItem($id, 'done');
    progressItem($id, 'todo');

    $this->artisan('work:roll-up-project-progress')->assertSuccessful();

    $live = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson("/api/v1/insights/projects/{$key}/health")
        ->assertOk()
        ->json('data.progress_percent');

    // The cache is rounded to the column's two decimals and the live figure to
    // one, so they are compared at the coarser of the two — the point is that
    // the same RULE produced both, not that two roundings match.
    expect(round((float) cachedProgress($id)->progress_cache, 1))->toBe(round((float) $live, 1));
});

it('stamps a project with no countable work rather than leaving it unexplained', function (): void {
    [$id] = progressProject();
    progressItem($id, 'cancelled');

    $this->artisan('work:roll-up-project-progress')->assertSuccessful();

    $row = cachedProgress($id);

    // The column is NOT NULL with a 0..100 check, so "nothing to measure"
    // cannot be said here. The timestamp says the figure is current; the
    // directory tells this case apart by the open-work count it already
    // carries, and the health page returns a null percentage for it.
    expect((float) $row->progress_cache)->toBe(0.0)
        ->and($row->progress_cached_at)->not->toBeNull();
});

it('leaves a project that has never been rolled up without a timestamp', function (): void {
    [$id] = progressProject();
    progressItem($id, 'done');

    // Before the command runs: a zero with no timestamp, which is what the
    // directory rendered for every project from Phase 2 until this shipped.
    // The interface shows nothing rather than a confident 0%.
    expect(cachedProgress($id)->progress_cached_at)->toBeNull();
});

it('rolls up every organization, not only the one someone is signed in to', function (): void {
    // A scheduled command has no tenant context and needs none: this is
    // maintenance over a column. Globex's project must be recomputed too.
    $globex = '01900003-0000-7000-8000-000000000009';

    $this->artisan('work:roll-up-project-progress')->assertSuccessful();

    expect(cachedProgress($globex)->progress_cached_at)->not->toBeNull();
});
