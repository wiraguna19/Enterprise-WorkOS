<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Work item templates (ADR 0047).
 *
 * docs/10 names templates twice and the only one in the schema lived inside a
 * recurrence, where no form could reach it. These tests are mostly about the
 * refusals, because the refusals are the design: a template holds no people, no
 * absolute dates and no project; it holds nothing the create form would refuse;
 * and it never fills in nothing.
 *
 * And one test is about who can READ one, because that is the mistake ADR 0038
 * nearly shipped: a create form reading an administration endpoint.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // work_item_template.manage
    $this->employee = $this->loginAs('sarah@acme.test');  // work_item.create, not manage
    $this->viewer = $this->loginAs('tono@acme.test');     // neither
});

/**
 * Write one and hand back its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function writeTemplate(string $token, array $overrides = []): string
{
    return (string) test()->withToken($token)
        ->postJson('/api/v1/work-item-templates', array_merge([
            'name' => 'Bug report '.now()->getTimestampMs(),
            'purpose' => 'Something is broken and somebody has to say how.',
            'fields' => ['type' => 'incident', 'priority' => 'high', 'due_in_days' => 2],
        ], $overrides))
        ->assertStatus(201)
        ->json('data.id');
}

it('keeps a template and serves it back as it was written', function (): void {
    $id = writeTemplate($this->admin, [
        'name' => 'Access request',
        'fields' => [
            // No trailing space: TrimStrings trims every input string before
            // any validator sees it, so a prefix like "Access: " arrives as
            // "Access:". The editor's hint was written to match.
            'title' => 'Access request for',
            'type' => 'request',
            'estimate_hours' => 0.5,
            'due_in_days' => 3,
        ],
    ]);

    $template = collect($this->withToken($this->admin)
        ->getJson('/api/v1/work-item-templates')
        ->assertOk()
        ->json('data'))
        ->firstWhere('id', $id);

    expect($template['name'])->toBe('Access request')
        ->and($template['fields']['title'])->toBe('Access request for')
        ->and($template['fields']['type'])->toBe('request')
        ->and($template['fields']['due_in_days'])->toBe(3);
});

it('lets the person filling in the form read templates without administering them', function (): void {
    $id = writeTemplate($this->admin);

    // The whole reason the read is behind `work_item.create`. If it sat behind
    // the administration permission, the picker would be empty for exactly the
    // people it is for — and an empty picker reads as "none exist".
    $ids = collect($this->withToken($this->employee)
        ->getJson('/api/v1/work-item-templates')
        ->assertOk()
        ->json('data'))
        ->pluck('id')
        ->all();

    expect($ids)->toContain($id);

    $this->withToken($this->employee)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Mine',
            'fields' => ['type' => 'task'],
        ])
        ->assertForbidden();

    $this->withToken($this->employee)
        ->deleteJson("/api/v1/work-item-templates/{$id}")
        ->assertForbidden();
});

it('refuses the list to somebody who cannot create work', function (): void {
    $this->withToken($this->viewer)
        ->getJson('/api/v1/work-item-templates')
        ->assertForbidden();
});

it('refuses people, a project and absolute dates by name, all at once', function (): void {
    $response = $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Too specific',
            'fields' => [
                'type' => 'task',
                'assignee_id' => '01900000-0000-7000-8000-000000000203',
                'project_id' => '01900003-0000-7000-8000-000000000001',
                'due_at' => '2026-12-31',
                'colour' => 'red',
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'work_item_template.not_templatable');

    // Every offender in one answer, each with its own reason — including the
    // one that is not a work item field at all.
    expect(array_keys($response->json('error.details.fields')))
        ->toEqualCanonicalizing(['assignee_id', 'project_id', 'due_at', 'colour']);
});

it('refuses a template that fills in nothing', function (): void {
    // Blanks are dropped before the check, so a form sent with every input
    // empty is refused rather than saved as a template that changes nothing.
    $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Nothing',
            'fields' => ['title' => '', 'priority' => null],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'work_item_template.empty');
});

it('refuses a type the model does not have', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Odd type',
            'fields' => ['type' => 'epic'],
        ])
        ->assertStatus(422);
});

it('refuses a second template with the same name, whatever its case', function (): void {
    writeTemplate($this->admin, ['name' => 'Incident']);

    $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'incident',
            'fields' => ['type' => 'incident'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'work_item_template.name_taken');

    // The refused insert must not poison the test's transaction: the service
    // saves inside a savepoint so this read still works.
    expect(DB::table('work_item_templates')->whereRaw('lower(name) = ?', ['incident'])->count())
        ->toBe(1);
});

it('holds custom field answers only for fields that exist and accept them', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/custom-fields/work_item', [
            'key' => 'severity',
            'label' => 'Severity',
            'type' => 'select',
            'config' => ['options' => ['S1', 'S2', 'S3']],
        ])
        ->assertStatus(201);

    $id = writeTemplate($this->admin, [
        'name' => 'Sev template',
        'fields' => ['type' => 'incident', 'custom_fields' => ['severity' => 'S2']],
    ]);

    $template = collect($this->withToken($this->employee)
        ->getJson('/api/v1/work-item-templates')
        ->json('data'))
        ->firstWhere('id', $id);

    expect($template['fields']['custom_fields'])->toEqual(['severity' => 'S2']);

    // An option the field does not offer is the refusal the create form would
    // make — so the template is refused it first, rather than prefilling a
    // form that then cannot be submitted.
    $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Bad sev',
            'fields' => ['custom_fields' => ['severity' => 'S9']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'custom_field.not_an_option');

    $this->withToken($this->admin)
        ->postJson('/api/v1/work-item-templates', [
            'name' => 'Ghost field',
            'fields' => ['custom_fields' => ['no_such_field' => 'x']],
        ])
        ->assertJsonPath('error.code', 'custom_field.unknown');
});

it('replaces the prefill on save, so a cleared field stays cleared', function (): void {
    $id = writeTemplate($this->admin, [
        'fields' => ['type' => 'incident', 'priority' => 'urgent'],
    ]);

    $this->withToken($this->admin)
        ->patchJson("/api/v1/work-item-templates/{$id}", [
            'fields' => ['type' => 'incident'],
        ])
        ->assertOk();

    $fields = DB::table('work_item_templates')->where('id', $id)->value('fields');

    // toEqual, not toBe: jsonb does not keep key order.
    expect(json_decode((string) $fields, true))->toEqual(['type' => 'incident']);
});

it('refuses a person on EDIT too, rather than dropping the key and answering 200', function (): void {
    $id = writeTemplate($this->admin);

    $this->withToken($this->admin)
        ->patchJson("/api/v1/work-item-templates/{$id}", [
            'fields' => ['type' => 'task', 'reviewer_id' => '01900000-0000-7000-8000-000000000203'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'work_item_template.not_templatable');
});

it('deletes, and the audit log keeps what the template held', function (): void {
    $id = writeTemplate($this->admin, [
        'name' => 'Short-lived',
        'fields' => ['type' => 'review', 'priority' => 'low'],
    ]);

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/work-item-templates/{$id}")
        ->assertNoContent();

    expect(DB::table('work_item_templates')->where('id', $id)->exists())->toBeFalse();

    $recorded = DB::table('audit_logs')
        ->where('event', 'work_item_template.deleted')
        ->where('target_id', $id)
        ->value('metadata');

    expect($recorded)->not->toBeNull();

    $metadata = json_decode((string) $recorded, true);

    expect($metadata['name'])->toBe('Short-lived')
        ->and($metadata['fields'])->toEqual(['type' => 'review', 'priority' => 'low']);
});

it('answers 404 for a template that does not exist', function (): void {
    $this->withToken($this->admin)
        ->patchJson('/api/v1/work-item-templates/01900000-0000-7000-8000-00000000dead', [
            'name' => 'Nobody',
        ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'work_item_template.unknown');
});

it('serves the vocabulary a form offers, from the model and not from a copy', function (): void {
    // `vocabulary` is registered before `work-items/{reference}`; after it, the
    // word would be read as a reference and answered with a 404.
    $this->withToken($this->viewer)
        ->getJson('/api/v1/work-items/vocabulary')
        ->assertOk()
        ->assertJsonPath('data.priorities', ['low', 'medium', 'high', 'urgent'])
        ->assertJsonFragment(['types' => [
            'task', 'request', 'approval_work', 'incident', 'review', 'campaign', 'operational',
        ]]);
});
