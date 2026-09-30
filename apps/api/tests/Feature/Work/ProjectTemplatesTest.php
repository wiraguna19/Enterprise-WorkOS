<?php

declare(strict_types=1);

/**
 * A project can keep its own templates (ADR 0058).
 *
 * What these hold: who may write one (the project's owner and managers, not
 * only an administrator), who may read one (whoever can see the project, and
 * nobody else), and that names are unique per project rather than per
 * organization.
 */
const PT_SARAH = '01900000-0000-7000-8000-000000000203';

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');     // work_item_template.manage
    $this->ahmad = $this->loginAs('ahmad@acme.test');    // a manager, without it
});

/** A project Ahmad owns, so he is its manager and nobody else is on it. */
function templateProject(): string
{
    static $n = 0;
    $n++;

    $key = "PT{$n}";

    test()->withToken(test()->ahmad)
        ->postJson('/api/v1/projects', ['key' => $key, 'name' => "Template fixture {$n}"])
        ->assertCreated();

    return $key;
}

/** @return list<string> template names as this token lists them */
function templateNames(string $token): array
{
    return array_column(test()->withToken($token)->getJson('/api/v1/work-item-templates')->assertOk()->json('data'), 'name');
}

it('lets a project owner keep templates for the project, without the organization-wide permission', function (): void {
    $key = templateProject();

    $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
        'name' => 'Release checklist',
        'project' => $key,
        'fields' => ['type' => 'task', 'priority' => 'high'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.project.key', $key);

    // But not the organization's: that is still an administrator's.
    $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
        'name' => 'For everyone',
        'fields' => ['type' => 'task'],
    ])->assertForbidden();
});

it('shows a project template only to people who can see the project', function (): void {
    $key = templateProject();

    $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
        'name' => 'Only for this project',
        'project' => $key,
        'fields' => ['type' => 'task'],
    ])->assertCreated();

    $this->flushHeaders();
    $sarah = $this->loginAs('sarah@acme.test');

    expect(templateNames($sarah))->not->toContain('Only for this project');

    $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/members", ['membership_id' => PT_SARAH, 'role' => 'member'])
        ->assertCreated();

    expect(templateNames($sarah))->toContain('Only for this project');
});

it('keeps names unique per project, not per organization', function (): void {
    $one = templateProject();
    $two = templateProject();

    foreach ([$one, $two] as $key) {
        $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
            'name' => 'Bug report',
            'project' => $key,
            'fields' => ['type' => 'task'],
        ])->assertCreated();
    }

    $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
        'name' => 'bug REPORT',
        'project' => $one,
        'fields' => ['type' => 'task'],
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'work_item_template.name_taken');
});

it('lets a member use a project template and not change it', function (): void {
    $key = templateProject();

    $id = $this->withToken($this->ahmad)->postJson('/api/v1/work-item-templates', [
        'name' => 'Not hers to edit',
        'project' => $key,
        'fields' => ['type' => 'task'],
    ])->json('data.id');

    $this->withToken($this->ahmad)
        ->postJson("/api/v1/projects/{$key}/members", ['membership_id' => PT_SARAH, 'role' => 'member'])
        ->assertCreated();

    $this->flushHeaders();
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)
        ->patchJson("/api/v1/work-item-templates/{$id}", ['name' => 'Renamed'])
        ->assertForbidden();

    // An administrator may, whichever project it belongs to.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/work-item-templates/{$id}", ['name' => 'Renamed by an admin'])
        ->assertOk();
});

it('answers 404 for a project the writer cannot see', function (): void {
    $this->flushHeaders();
    $sarah = $this->loginAs('sarah@acme.test');

    // FIN is private and Sarah is not on it.
    $this->withToken($sarah)->postJson('/api/v1/work-item-templates', [
        'name' => 'Sneaking in',
        'project' => 'FIN',
        'fields' => ['type' => 'task'],
    ])->assertNotFound();
});
