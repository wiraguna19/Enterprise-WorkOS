<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The fields an organization declares for itself (ADR 0038).
 *
 * Custom fields were specified in five documents and built nowhere, for six
 * phases. These tests are the first thing that has ever asked whether they
 * exist — so several of them assert the plain fact that an endpoint answers,
 * which is usually a weak test and is the entire point here.
 *
 * The rest are about the refusals, because the refusals are the design: a key
 * is frozen, a type is frozen, a select needs options, and retiring is not
 * deleting.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // custom_field.manage
    $this->manager = $this->loginAs('ahmad@acme.test');   // not
});

/**
 * Declare one and hand back its id.
 *
 * The token is a parameter rather than read off the test case: a global helper
 * reaching into `$this` is how a test file starts depending on another file's
 * beforeEach, and the failure when it breaks names the wrong test.
 *
 * @param array<string, mixed> $overrides
 */
function declareCustomField(string $token, array $overrides = []): string
{
    return (string) test()->withToken($token)
        ->postJson('/api/v1/custom-fields/work_item', array_merge([
            'key' => 'client'.now()->getTimestampMs(),
            'label' => 'Client',
            'type' => 'text',
        ], $overrides))
        ->assertStatus(201)
        ->json('data.id');
}

it('declares a field and says what a filter would call it', function (): void {
    $field = $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'client',
            'label' => 'Client',
            'type' => 'text',
        ])
        ->assertStatus(201)
        ->json('data');

    // `filter_key` is served rather than assembled on the client, so the `cf_`
    // prefix of docs/05 §4 is decided in one place.
    expect($field['filter_key'])->toBe('cf_client')
        ->and($field['live'])->toBeTrue()
        ->and($field['required'])->toBeFalse();
});

it('refuses a second field with the same key, from the index and not from a read', function (): void {
    declareCustomField($this->admin, ['key' => 'duplicate_key']);

    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'duplicate_key',
            'label' => 'Another',
            'type' => 'text',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'custom_field.key_taken');
});

it('refuses a key that could not survive a URL', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => '2-Clients!',
            'label' => 'Client',
            'type' => 'text',
        ])
        ->assertStatus(422);
});

it('refuses a select with no options, rather than shipping an empty picker', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'stage',
            'label' => 'Stage',
            'type' => 'select',
            'config' => ['options' => []],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.select_needs_options');
});

it('keeps a select\'s options, without duplicates', function (): void {
    $id = declareCustomField($this->admin, [
        'key' => 'stage_options',
        'label' => 'Stage',
        'type' => 'select',
        'config' => ['options' => ['Draft', 'Draft', ' Final ']],
    ]);

    $field = collect($this->withToken($this->admin)
        ->getJson('/api/v1/custom-fields/work_item')
        ->assertOk()
        ->json('data'))
        ->firstWhere('id', $id);

    expect($field['options'])->toBe(['Draft', 'Final']);
});

it('lets the label change and refuses the key BY NAME', function (): void {
    $id = declareCustomField($this->admin, ['key' => 'frozen_key', 'label' => 'Client']);

    $this->withToken($this->admin)
        ->patchJson("/api/v1/custom-fields/work_item/{$id}", ['label' => 'Customer'])
        ->assertOk()
        ->assertJsonPath('data.label', 'Customer');

    // Refused, not silently dropped. A validator that ignores the field leaves
    // the screen showing a renamed key until it reloads.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/custom-fields/work_item/{$id}", ['key' => 'something_else'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.key_is_frozen');
});

it('refuses a type change, because the answers are in a column chosen by it', function (): void {
    $id = declareCustomField($this->admin, ['key' => 'frozen_type']);

    $this->withToken($this->admin)
        ->patchJson("/api/v1/custom-fields/work_item/{$id}", ['type' => 'number'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.type_is_frozen');
});

it('retires a field without destroying anything', function (): void {
    $id = declareCustomField($this->admin, ['key' => 'retire_me']);

    $this->withToken($this->admin)
        ->postJson("/api/v1/custom-fields/work_item/{$id}/live", ['live' => false])
        ->assertOk()
        ->assertJsonPath('data.live', false);

    // Still listed for the administrator: retired is a state, not an absence,
    // and this screen is the only place anybody can see that it exists.
    $keys = collect($this->withToken($this->admin)
        ->getJson('/api/v1/custom-fields/work_item')
        ->assertOk()
        ->json('data'))
        ->pluck('id');

    expect($keys)->toContain($id);

    $this->withToken($this->admin)
        ->postJson("/api/v1/custom-fields/work_item/{$id}/live", ['live' => true])
        ->assertOk()
        ->assertJsonPath('data.live', true);
});

it('records how many answers a deletion destroyed', function (): void {
    $id = declareCustomField($this->admin, ['key' => 'delete_me']);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/custom-fields/work_item/{$id}")
        ->assertNoContent();

    $entry = DB::table('audit_logs')
        ->where('event', 'custom_field.deleted')
        ->orderByDesc('occurred_at')
        ->first();

    expect($entry)->not->toBeNull()
        // Counted BEFORE the cascade, or the number is always zero and the
        // audit entry says a deletion cost nothing whatever it cost.
        ->and(json_decode((string) $entry->metadata, true))
        ->toHaveKey('answers_destroyed');
});

it('puts the fields in the order the administrator chose', function (): void {
    $first = declareCustomField($this->admin, ['key' => 'order_a', 'label' => 'A']);
    $second = declareCustomField($this->admin, ['key' => 'order_b', 'label' => 'B']);

    $order = collect($this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item/order', ['order' => [$second, $first]])
        ->assertOk()
        ->json('data'))
        ->pluck('id')
        ->values()
        ->all();

    expect(array_search($second, $order, true))
        ->toBeLessThan(array_search($first, $order, true));
});

it('does not let a work-item field be reached through the project scope', function (): void {
    $id = declareCustomField($this->admin, ['key' => 'scoped_field']);

    // Not a 403: an address where nothing of that kind lives answers 404, or
    // one screen can edit the other's fields by accident.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/custom-fields/project/{$id}", ['label' => 'Moved'])
        ->assertStatus(404);
});

it('refuses everything to somebody without custom_field.manage', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/custom-fields/work_item')
        ->assertForbidden();

    $this->withToken($this->manager)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'sneaky',
            'label' => 'Sneaky',
            'type' => 'text',
        ])
        ->assertForbidden();
});
