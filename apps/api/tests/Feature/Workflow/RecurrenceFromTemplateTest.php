<?php

declare(strict_types=1);

use App\Modules\Workflow\Application\Service\RecurrenceMaterializer;
use Illuminate\Support\Facades\DB;

/**
 * A recurrence can carry what a work item template carries (ADR 0047).
 *
 * The recurrence form offers the same templates the create form does; what the
 * form has no field for — type, description, estimate, the organization's own
 * fields — travels in the recurrence's template and lands on every occurrence.
 * These hold the part the API owns: that a custom-field answer survives from
 * the recurrence to the work it makes.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

it('gives every occurrence the custom-field answers it was set up with', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/custom-fields/work_item', [
        'key' => 'client',
        'label' => 'Client',
        'type' => 'text',
    ])->assertStatus(201);

    $id = $this->withToken($this->admin)->postJson('/api/v1/recurrences', [
        'rrule' => 'FREQ=DAILY;INTERVAL=1',
        'template' => [
            'title' => 'Weekly client report',
            'type' => 'task',
            'estimate_hours' => 2,
            'custom_fields' => ['client' => 'Acme'],
        ],
    ])->assertStatus(201)->json('data.id');

    // Due now, rather than tomorrow: the materializer takes what is due.
    DB::table('recurrences')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);

    expect(app(RecurrenceMaterializer::class)->run()['created'])->toBeGreaterThanOrEqual(1);

    $reference = DB::table('work_items')->where('recurrence_id', $id)->value('reference');

    expect($reference)->not->toBeNull();

    // Made for the person who set it up. The first run of this test got a 404
    // here: the tick runs as nobody, and an item with no creator, no project
    // and no assignee was visible to no one — Rina included.
    expect(DB::table('work_items')->where('reference', $reference)->value('created_by_membership_id'))
        ->toBe('01900000-0000-7000-8000-000000000201');

    $item = $this->withToken($this->admin)->getJson("/api/v1/work-items/{$reference}")->assertOk()->json('data');

    $client = collect($item['custom_fields'])->firstWhere('key', 'client');

    expect($client['value'] ?? null)->toBe('Acme')
        ->and((float) $item['estimate_hours'])->toBe(2.0);
});

it('refuses custom-field answers that are not a map of strings', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/recurrences', [
        'rrule' => 'FREQ=DAILY;INTERVAL=1',
        'template' => [
            'title' => 'Malformed',
            'custom_fields' => ['client' => ['nested' => 'no']],
        ],
    ])->assertUnprocessable();
});
