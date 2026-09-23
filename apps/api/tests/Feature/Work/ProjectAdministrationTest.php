<?php

declare(strict_types=1);

use App\Modules\Work\Infrastructure\Eloquent\ProjectModel;
use Illuminate\Support\Facades\DB;

/**
 * A project could be created and never corrected (ADR 0040).
 *
 * `PATCH /projects/{key}` was not a route and `update()` was not a method,
 * while `project.update`, `project.archive`, `project.delete` and
 * `project.manage_members` were seeded in Phase 1, granted to roles, and
 * answered by `ProjectPolicy`. **A permission consulted by a policy looks
 * consulted**, which is why the guard that watches for permissions with
 * nothing behind them stayed green for seven phases.
 *
 * So the plain assertions below — that a rename works at all — are the point,
 * and not a formality.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
    $this->viewer = $this->loginAs('tono@acme.test');   // contractor: reads only
});

/**
 * The seeded Acme project.
 *
 * Read fresh every call rather than memoized: these tests save through the API
 * and then assert on the row, and a cached model would hand back the version
 * from before the save — which is how an optimistic-locking test passes while
 * proving nothing.
 */
function engProject(): ProjectModel
{
    return ProjectModel::query()->where('key', 'ENG')->firstOrFail();
}

it('renames a project, and says so in its history', function (): void {
    $project = engProject();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/projects/{$project->key}", [
            'name' => 'Platform Rebuild (renamed)',
            'lock_version' => $project->lock_version,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Platform Rebuild (renamed)');

    $entry = DB::table('activity_logs')
        ->where('subject_type', 'project')
        ->where('subject_id', $project->id)
        ->where('verb', 'updated')
        ->orderByDesc('occurred_at')
        ->first();

    expect($entry)->not->toBeNull();
});

it('refuses the key by name rather than dropping it', function (): void {
    // Silently ignoring it would leave the screen showing a renamed key until
    // it reloaded — and the key is in every work item reference this project
    // has produced, so changing it is a migration, not an edit.
    $this->withToken($this->admin)
        ->patchJson('/api/v1/projects/ENG', ['key' => 'OTHER'])
        ->assertStatus(422);

    expect(ProjectModel::query()->where('key', 'ENG')->exists())->toBeTrue();
});

it('answers 409 rather than overwriting somebody else', function (): void {
    $project = engProject();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/projects/{$project->key}", [
            'name' => 'First writer',
            'lock_version' => $project->lock_version,
        ])
        ->assertOk();

    // The second writer started from the version before that save.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/projects/{$project->key}", [
            'name' => 'Second writer',
            'lock_version' => $project->lock_version,
        ])
        ->assertStatus(409);

    expect(engProject()->name)->toBe('First writer');
});

it('writes nothing when nothing changed', function (): void {
    $project = engProject();

    $this->withToken($this->admin)
        ->patchJson("/api/v1/projects/{$project->key}", ['name' => $project->name])
        ->assertOk();

    // The version does NOT move for a no-op. A save that bumps it anyway makes
    // every other open form stale for a change nobody made.
    expect(engProject()->lock_version)->toBe($project->lock_version);
});

it('archives and restores without deleting anything', function (): void {
    $project = engProject();
    $itemsBefore = DB::table('work_items')->where('project_id', $project->id)->count();

    $this->withToken($this->admin)
        ->postJson("/api/v1/projects/{$project->key}/archive", ['archived' => true])
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    // Off the directory by default...
    $keys = collect($this->withToken($this->admin)->getJson('/api/v1/projects')->json('data'))
        ->pluck('key');

    expect($keys)->not->toContain('ENG')
        // ...and still entirely present.
        ->and(DB::table('work_items')->where('project_id', $project->id)->count())
        ->toBe($itemsBefore);

    $this->withToken($this->admin)
        ->postJson("/api/v1/projects/{$project->key}/archive", ['archived' => false])
        ->assertOk()
        ->assertJsonPath('data.archived', false);
});

it('keeps archiving separate from status', function (): void {
    // "On hold" and "put away" are different answers. Conflating them would
    // make a project resumed from hold come back as whatever it was archived
    // as.
    $this->withToken($this->admin)
        ->patchJson('/api/v1/projects/ENG', ['status' => 'on_hold'])
        ->assertOk()
        ->assertJsonPath('data.status', 'on_hold')
        ->assertJsonPath('data.archived', false);
});

it('refuses a status the database would refuse', function (): void {
    $this->withToken($this->admin)
        ->patchJson('/api/v1/projects/ENG', ['status' => 'activ'])
        ->assertStatus(422);
});

it('refuses somebody who may read the project but not change it', function (): void {
    $this->withToken($this->viewer)
        ->patchJson('/api/v1/projects/ENG', ['name' => 'Not yours'])
        ->assertForbidden();

    $this->withToken($this->viewer)
        ->postJson('/api/v1/projects/ENG/archive', ['archived' => true])
        ->assertForbidden();
});
