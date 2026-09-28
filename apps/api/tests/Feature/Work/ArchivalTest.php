<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Closed work leaves the working set (ADR 0054).
 *
 * Archived is a flag, not a move: the item still opens, still counts in every
 * report, still turns up in search. What the tests hold is WHICH screens stop
 * carrying it — the browse list and a board — and that nothing can archive
 * open work or leave reopened work archived.
 */
const ARCHIVE_ACME = '01900000-0000-7000-8000-0000000000ac';

beforeEach(function (): void {
    $this->rina = $this->loginAs('rina@acme.test');
});

/**
 * Closed Acme items from the seed, oldest reference first.
 *
 * @return list<object{id: string, reference: string, project_id: string|null, workflow_state_id: string}>
 */
function closedAcmeItems(int $count): array
{
    /** @var list<object{id: string, reference: string, project_id: string|null, workflow_state_id: string}> $rows */
    $rows = DB::table('work_items')
        ->where('organization_id', ARCHIVE_ACME)
        ->where('state_category', 'done')
        ->whereNotNull('project_id')
        ->whereNull('deleted_at')
        ->orderBy('reference')
        ->limit($count)
        ->get(['id', 'reference', 'project_id', 'workflow_state_id'])
        ->all();

    expect(count($rows))->toBe($count, 'The seed has fewer finished Acme items than this test needs.');

    return $rows;
}

function archiveNow(string $id): void
{
    DB::table('work_items')->where('id', $id)->update(['archived_at' => now()]);
}

it('archives work closed and untouched for longer than the organization keeps it, and nothing else', function (): void {
    DB::table('organizations')->where('id', ARCHIVE_ACME)->update(['archive_closed_after_days' => 30]);
    [$stale, $recent] = closedAcmeItems(2);

    DB::table('work_items')->where('id', $stale->id)->update(['updated_at' => now()->subDays(40)]);
    DB::table('work_items')->where('id', $recent->id)->update(['updated_at' => now()->subDays(10)]);

    $open = DB::table('work_items')
        ->where('organization_id', ARCHIVE_ACME)
        ->whereNotIn('state_category', ['done', 'cancelled'])
        ->value('id');
    DB::table('work_items')->where('id', $open)->update(['updated_at' => now()->subDays(400)]);

    $this->artisan('work:archive-closed-work')->assertSuccessful();

    expect(DB::table('work_items')->where('id', $stale->id)->value('archived_at'))->not->toBeNull()
        ->and(DB::table('work_items')->where('id', $recent->id)->value('archived_at'))->toBeNull()
        ->and(DB::table('work_items')->where('id', $open)->value('archived_at'))->toBeNull();
});

it('archives nothing for an organization that said never', function (): void {
    DB::table('organizations')->where('id', ARCHIVE_ACME)->update(['archive_closed_after_days' => null]);
    [$stale] = closedAcmeItems(1);
    DB::table('work_items')->where('id', $stale->id)->update(['updated_at' => now()->subYears(5)]);

    $this->artisan('work:archive-closed-work')->assertSuccessful();

    expect(DB::table('work_items')->where('id', $stale->id)->value('archived_at'))->toBeNull();
});

it('leaves archived work out of the list unless asked, and can list the archive alone', function (): void {
    [$archived] = closedAcmeItems(1);
    archiveNow($archived->id);

    $references = fn (string $query): array => collect(
        $this->withToken($this->rina)->getJson('/api/v1/work-items?limit=100&filter[state_category]=done'.$query)
            ->assertOk()
            ->json('data'),
    )->pluck('reference')->all();

    expect($references(''))->not->toContain($archived->reference)
        ->and($references('&filter[archived]=include'))->toContain($archived->reference)
        ->and($references('&filter[archived]=only'))->toBe([$archived->reference]);
});

it('refuses an archive filter it does not know', function (): void {
    $this->withToken($this->rina)->getJson('/api/v1/work-items?filter[archived]=sometimes')
        ->assertStatus(422);
});

it('takes archived work off the board, and says how much', function (): void {
    [$archived] = closedAcmeItems(1);
    archiveNow($archived->id);

    $key = DB::table('projects')->where('id', $archived->project_id)->value('key');

    $column = collect($this->withToken($this->rina)->getJson("/api/v1/projects/{$key}/board")
        ->assertOk()
        ->json('data.columns'))
        ->first(fn (array $column): bool => $column['state']['id'] === $archived->workflow_state_id);

    expect($column['archived_count'])->toBeGreaterThanOrEqual(1)
        ->and(collect($column['items'])->pluck('reference'))->not->toContain($archived->reference);
});

it('still opens an archived item by its reference, and restores it', function (): void {
    [$archived] = closedAcmeItems(1);
    archiveNow($archived->id);

    $this->withToken($this->rina)->getJson("/api/v1/work-items/{$archived->reference}")
        ->assertOk()
        ->assertJsonPath('data.archived_at', fn (?string $at): bool => $at !== null);

    $this->withToken($this->rina)->postJson("/api/v1/work-items/{$archived->reference}/restore")
        ->assertOk()
        ->assertJsonPath('data.archived_at', null);

    expect(DB::table('activity_logs')
        ->where('subject_id', $archived->id)
        ->where('verb', 'restored')
        ->exists())->toBeTrue();
});

it('clears the flag when closed work is reopened, whoever reopens it', function (): void {
    [$archived] = closedAcmeItems(1);
    archiveNow($archived->id);

    // Straight at the table: the trigger is what guarantees it, so no writer —
    // a rule, the workflow editor, a script — has to remember.
    DB::table('work_items')->where('id', $archived->id)->update([
        'state_category' => 'in_progress',
        'completed_at' => null,
    ]);

    expect(DB::table('work_items')->where('id', $archived->id)->value('archived_at'))->toBeNull();
});

it('cannot archive open work', function (): void {
    $open = DB::table('work_items')
        ->where('organization_id', ARCHIVE_ACME)
        ->whereNotIn('state_category', ['done', 'cancelled'])
        ->value('id');

    expect(fn () => DB::transaction(fn () => archiveNow((string) $open)))
        ->toThrow(QueryException::class, 'ck_wi_archived_is_closed');
});

it('lets the organization say how long closed work stays in view', function (): void {
    $this->withToken($this->rina)
        ->patchJson('/api/v1/organization/settings/archive-policy', ['archive_closed_after_days' => 30])
        ->assertOk()
        ->assertJsonPath('data.archive_closed_after_days', 30);

    $this->withToken($this->rina)
        ->patchJson('/api/v1/organization/settings/archive-policy', ['archive_closed_after_days' => null])
        ->assertOk()
        ->assertJsonPath('data.archive_closed_after_days', null);

    $this->withToken($this->rina)
        ->patchJson('/api/v1/organization/settings/archive-policy', ['archive_closed_after_days' => 3])
        ->assertStatus(422);

    $this->withToken($this->rina)->getJson('/api/v1/organization/settings')
        ->assertJsonPath('data.archive_closed_after_days', null);
});
