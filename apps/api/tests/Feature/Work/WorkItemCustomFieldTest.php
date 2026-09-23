<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\WorkItemModel;

/**
 * The organization's own fields, on the record they belong to (ADR 0038).
 *
 * The definition side has its own tests. These are about the half that makes it
 * a feature rather than a settings screen: a value is written with the item,
 * comes back with the item, and the rules about `required` hold in both
 * directions.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // custom_field.manage
    $this->manager = $this->loginAs('ahmad@acme.test');   // work_item.create, not manage
});

/** @param array<string, mixed> $overrides */
function declareWorkItemField(string $token, array $overrides = []): string
{
    return (string) test()->withToken($token)
        ->postJson('/api/v1/custom-fields/work_item', array_merge([
            'key' => 'client',
            'label' => 'Client',
            'type' => 'text',
        ], $overrides))
        ->assertStatus(201)
        ->json('data.key');
}

it('carries the answer back with the item, and only on the detail endpoint', function (): void {
    declareWorkItemField($this->admin);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', [
            'custom_fields' => ['client' => 'Acme'],
        ])
        ->assertOk();

    $fields = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144')
        ->assertOk()
        ->json('data.custom_fields');

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['key'])->toBe('client')
        ->and($fields[0]['value'])->toBe('Acme');

    // A list endpoint must not carry them: a column per declared field on a
    // board is a board nobody can read, and building one is a join per row.
    $row = collect($this->withToken($this->manager)
        ->getJson('/api/v1/work-items?limit=1')
        ->assertOk()
        ->json('data'))
        ->first();

    expect($row)->not->toHaveKey('custom_fields');
});

it('saves an answer even when no column changed', function (): void {
    declareWorkItemField($this->admin);

    $item = WorkItemModel::query()->where('reference', 'ENG-144')->firstOrFail();

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', [
            'custom_fields' => ['client' => 'Globex'],
            'lock_version' => $item->lock_version,
        ])
        ->assertOk();

    // The update path returns early when no column differs. An answer written
    // after that check would be thrown away and the request would still answer
    // 200 — a save that reports success and stores nothing.
    $value = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144')
        ->json('data.custom_fields.0.value');

    expect($value)->toBe('Globex');
});

it('refuses a key the organization never declared', function (): void {
    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', [
            'custom_fields' => ['not_a_field' => 'x'],
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'custom_field.unknown');
});

it('refuses a value that is not one of a select\'s options', function (): void {
    declareWorkItemField($this->admin, [
        'key' => 'stage',
        'label' => 'Stage',
        'type' => 'select',
        'config' => ['options' => ['Draft', 'Final']],
    ]);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['stage' => 'Halfway']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.not_an_option');
});

it('keeps a number exact rather than rounding it into a float', function (): void {
    declareWorkItemField($this->admin, ['key' => 'budget', 'label' => 'Budget', 'type' => 'number']);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['budget' => '12345678.9012']])
        ->assertOk();

    $value = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144')
        ->json('data.custom_fields.0.value');

    // A string, and every digit of it. The column is numeric(18,4) so that a
    // quantity survives; casting to a double on the way out would undo that in
    // the one hop where nothing upstream looks wrong.
    expect($value)->toBe('12345678.9012');
});

it('demands a required field when the item is CREATED', function (): void {
    declareWorkItemField($this->admin, ['key' => 'owner_team', 'label' => 'Owner team', 'required' => true]);

    $this->withToken($this->manager)
        ->postJson('/api/v1/work-items', ['title' => 'Missing its required field'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.required');

    $this->withToken($this->manager)
        ->postJson('/api/v1/work-items', [
            'title' => 'Has it',
            'custom_fields' => ['owner_team' => 'Platform'],
        ])
        ->assertStatus(201);
});

it('does not make a new required field block edits to older items', function (): void {
    // ENG-144 exists before the field does — the ordinary case, and the one
    // that turns "required" into a lockout if it is checked on every write.
    declareWorkItemField($this->admin, ['key' => 'owner_team', 'label' => 'Owner team', 'required' => true]);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['title' => 'Renamed, nothing else'])
        ->assertOk();
});

it('refuses to take a required answer away once it exists', function (): void {
    declareWorkItemField($this->admin, ['key' => 'owner_team', 'label' => 'Owner team', 'required' => true]);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['owner_team' => 'Platform']])
        ->assertOk();

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['owner_team' => null]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.required');
});

it('keeps a retired field\'s answer readable and refuses to change it', function (): void {
    declareWorkItemField($this->admin);

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['client' => 'Acme']])
        ->assertOk();

    $id = collect($this->withToken($this->admin)
        ->getJson('/api/v1/custom-fields/work_item')
        ->json('data'))
        ->firstWhere('key', 'client')['id'];

    $this->withToken($this->admin)
        ->postJson("/api/v1/custom-fields/work_item/{$id}/live", ['live' => false])
        ->assertOk();

    $fields = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/ENG-144')
        ->json('data.custom_fields');

    // Still printed — retiring stops the form asking, it does not rewrite the
    // record — and flagged so the form knows not to offer a control.
    expect($fields)->toHaveCount(1)
        ->and($fields[0]['value'])->toBe('Acme')
        ->and($fields[0]['live'])->toBeFalse();

    $this->withToken($this->manager)
        ->patchJson('/api/v1/work-items/ENG-144', ['custom_fields' => ['client' => 'Globex']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.retired');
});

it('offers the blank form to somebody who may create work but not administer fields', function (): void {
    declareWorkItemField($this->admin, ['key' => 'owner_team', 'label' => 'Owner team', 'required' => true]);

    // The administration endpoint is closed to them...
    $this->withToken($this->manager)
        ->getJson('/api/v1/custom-fields/work_item')
        ->assertForbidden();

    // ...and without this one, declaring a single required field would make
    // creating a work item impossible for everybody but an administrator.
    $fields = $this->withToken($this->manager)
        ->getJson('/api/v1/work-items/fields')
        ->assertOk()
        ->json('data');

    expect($fields)->toHaveCount(1)
        ->and($fields[0]['key'])->toBe('owner_team')
        ->and($fields[0]['value'])->toBeNull();
});
