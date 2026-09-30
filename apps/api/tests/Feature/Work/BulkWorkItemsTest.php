<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * One change to many work items (docs/05 §5, ADR 0055).
 *
 * What these hold: each subject is answered on its own — a refusal for one
 * never undoes or blocks the others — and every refusal comes back with the
 * code and sentence a single change would have produced. Plus the two
 * shortcuts a bulk path is tempted into and must not take: writing without
 * the per-item policy, and writing without the per-item history.
 */
const BULK_MAYA = '01900000-0000-7000-8000-000000000205';

const BULK_SARAH = '01900000-0000-7000-8000-000000000203';

/** Left the organization in the seed. */
const BULK_REVOKED = '01900000-0000-7000-8000-000000000209';

/** Gil's membership, in Globex. */
const BULK_GLOBEX = '01900000-0000-7000-8000-000000000301';

beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/**
 * Fresh ENG items, made the way a person makes them — so every assertion is
 * about rows this test created, never about the seed's.
 *
 * @param  array<string, mixed>  $extra
 * @return list<string> references
 */
function bulkItems(int $count, array $extra = []): array
{
    $project = test()->withToken(test()->ahmad)->getJson('/api/v1/projects/ENG')->json('data.id');
    $references = [];

    for ($n = 1; $n <= $count; $n++) {
        $references[] = (string) test()->withToken(test()->ahmad)->postJson('/api/v1/work-items', [
            'title' => "Bulk subject {$n}",
            'type' => 'task',
            'project_id' => $project,
        ] + $extra)->assertCreated()->json('data.reference');
    }

    return $references;
}

function activeAssigneeOf(string $reference): ?string
{
    $value = DB::table('work_item_assignments as a')
        ->join('work_items as w', 'w.id', '=', 'a.work_item_id')
        ->where('w.reference', $reference)
        ->where('a.role', 'assignee')
        ->whereNull('a.unassigned_at')
        ->value('a.membership_id');

    return $value === null ? null : (string) $value;
}

function dueDayOf(string $reference): ?string
{
    $value = DB::table('work_items')->where('reference', $reference)->value('due_at');

    return $value === null ? null : substr((string) $value, 0, 10);
}

it('assigns and dates every item it is given, each through its own history', function (): void {
    $references = bulkItems(3);

    $body = $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        'references' => $references,
        'assignee_id' => BULK_MAYA,
        'due_at' => '2030-03-14T17:00:00',
    ])->assertOk()->json('data');

    expect($body['succeeded'])->toBe(3)
        ->and($body['failed'])->toBe(0)
        ->and(array_column($body['results'], 'changed'))->toBe([true, true, true]);

    foreach ($references as $reference) {
        expect(activeAssigneeOf($reference))->toBe(BULK_MAYA)
            ->and(dueDayOf($reference))->toBe('2030-03-14');

        // The same entries a single edit writes. A bulk path with its own
        // UPDATE would change the rows and leave the timeline silent.
        $verbs = DB::table('activity_logs as l')
            ->join('work_items as w', 'w.id', '=', 'l.subject_id')
            ->where('w.reference', $reference)
            ->pluck('l.verb')
            ->all();

        expect($verbs)->toContain('updated')->toContain('assigned');
    }
});

it('answers each subject on its own, and a refusal for one changes nothing for the others', function (): void {
    [$first, $second] = bulkItems(2);

    $body = $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        // GBX-1 exists — in another organization. The answer must be the one a
        // made-up reference gets, or this endpoint is a way to probe for them.
        'references' => [$first, 'GBX-1', $second, 'ENG-999999'],
        'assignee_id' => BULK_MAYA,
    ])->assertOk()->json('data');

    expect($body['succeeded'])->toBe(2)->and($body['failed'])->toBe(2);

    $byReference = collect($body['results'])->keyBy('reference');

    expect($byReference['GBX-1']['error']['code'])->toBe('work_item.not_found')
        ->and($byReference['ENG-999999']['error']['code'])->toBe('work_item.not_found')
        ->and($byReference['GBX-1']['error']['message'])->toBe('GBX-1 does not exist, or you cannot see it.')
        ->and(activeAssigneeOf($first))->toBe(BULK_MAYA)
        ->and(activeAssigneeOf($second))->toBe(BULK_MAYA);
});

it('asks the policy about every item, and writes nothing it refuses', function (): void {
    $references = bulkItems(2);

    // Sarah is an employee: she may read these, and may not assign work.
    $this->flushHeaders();
    $sarah = $this->loginAs('sarah@acme.test');

    $body = $this->withToken($sarah)->postJson('/api/v1/work-items/bulk', [
        'references' => $references,
        'assignee_id' => BULK_SARAH,
        'due_at' => '2030-01-01T17:00:00',
    ])->assertOk()->json('data');

    expect($body['succeeded'])->toBe(0)
        ->and(array_unique(array_column(array_column($body['results'], 'error'), 'code')))->toBe(['work_item.forbidden']);

    // Authorised BEFORE either write: the due date she may set is not set
    // either, so no item is left half-changed.
    foreach ($references as $reference) {
        expect(activeAssigneeOf($reference))->toBeNull()
            ->and(dueDayOf($reference))->toBeNull();
    }
});

it('counts an item that already has that person as done, not failed', function (): void {
    [$reference] = bulkItems(1, ['assignee_id' => BULK_MAYA]);

    $result = $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        'references' => [$reference],
        'assignee_id' => BULK_MAYA,
    ])->assertOk()->json('data.results.0');

    expect($result['ok'])->toBeTrue()->and($result['changed'])->toBeFalse();

    // And no second row: "already hers" wrote nothing.
    expect(DB::table('work_item_assignments as a')
        ->join('work_items as w', 'w.id', '=', 'a.work_item_id')
        ->where('w.reference', $reference)
        ->count())->toBe(1);
});

it('refuses a due date before the start by name, for that item only', function (): void {
    [$late] = bulkItems(1, ['start_date' => '2030-06-01']);
    [$free] = bulkItems(1);

    $body = $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        'references' => [$late, $free],
        'due_at' => '2030-05-01T17:00:00',
    ])->assertOk()->json('data');

    $byReference = collect($body['results'])->keyBy('reference');

    expect($byReference[$late]['error']['code'])->toBe('work_item.due_before_start')
        ->and($byReference[$late]['error']['message'])->toContain('starts on 2030-06-01')
        ->and(dueDayOf($late))->toBeNull()
        ->and(dueDayOf($free))->toBe('2030-05-01');
});

it('refuses the same thing on a single edit with a sentence, not a 500', function (): void {
    // The defect the bulk path would have inherited: moving only the due date
    // was checked against the stored start by nothing above the CHECK
    // constraint, and Postgres's refusal came back as a server error.
    [$reference] = bulkItems(1, ['start_date' => '2030-06-01']);

    $this->withToken($this->ahmad)
        ->patchJson("/api/v1/work-items/{$reference}", ['due_at' => '2030-05-01T17:00:00'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'work_item.due_before_start');
});

it('clears a due date when told null', function (): void {
    [$reference] = bulkItems(1, ['due_at' => '2030-02-02T17:00:00']);

    $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        'references' => [$reference],
        'due_at' => null,
    ])->assertOk()->assertJsonPath('data.results.0.changed', true);

    expect(dueDayOf($reference))->toBeNull();
});

it('refuses a request that is wrong for every subject at once', function (array $body, string $field): void {
    $this->withToken($this->ahmad)
        ->postJson('/api/v1/work-items/bulk', $body)
        // This API's envelope, not Laravel's default: the field names are under
        // `error.details` (docs/05 §3).
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'nothing to change' => [['references' => ['ENG-1']], 'references'],
    'no subjects' => [['references' => [], 'assignee_id' => BULK_MAYA], 'references'],
    'over the bound' => [['references' => array_map(fn ($n) => "ENG-{$n}", range(1, 101)), 'assignee_id' => BULK_MAYA], 'references'],
    'someone who left' => [['references' => ['ENG-1'], 'assignee_id' => BULK_REVOKED], 'assignee_id'],
    'someone in another organization' => [['references' => ['ENG-1'], 'assignee_id' => BULK_GLOBEX], 'assignee_id'],
]);

it('leaves one audit entry naming what was asked and what happened', function (): void {
    [$reference] = bulkItems(1);

    $this->withToken($this->ahmad)->postJson('/api/v1/work-items/bulk', [
        'references' => [$reference, 'GBX-1'],
        'assignee_id' => BULK_MAYA,
    ])->assertOk();

    $entry = DB::table('audit_logs')->where('event', 'work_item.bulk_updated')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull();

    /** @var array<string, mixed> $metadata */
    $metadata = json_decode((string) $entry->metadata, true);

    expect($metadata['references'])->toBe([$reference, 'GBX-1'])
        ->and($metadata['succeeded'])->toBe(1)
        ->and($metadata['failed'])->toBe(1);
});
