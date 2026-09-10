<?php

declare(strict_types=1);

use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;

/**
 * Creating, renaming and moving a department through the API.
 *
 * These three endpoints shipped in Phase 2 and had **no test and no caller**
 * for six phases. Two of the three were broken the whole time: `update` and
 * `move` call `authorize('update', $department)`, no `DepartmentPolicy`
 * existed, and Gate's answer with no policy is deny — so they answered 403 to
 * everyone, org admins included.
 *
 * The two absences are the same absence. Nothing called them, so nothing was
 * written to test them, so the wall behind them was invisible until a screen
 * arrived. **An unreachable endpoint is not merely unused; it is unpoliced.**
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');     // org admin: every permission
    $this->manager = $this->loginAs('ahmad@acme.test');  // department.view only
});

it('creates a department, renames it, and moves it under another', function (): void {
    $created = $this->withToken($this->admin)->postJson('/api/v1/departments', [
        'name' => 'Developer Experience',
        'code' => 'DEVEX',
    ])->assertCreated()->json('data');

    expect($created['parent_id'])->toBeNull()
        ->and($created['depth'])->toBe(0);

    // Renaming is a correction and keeps everything else.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/departments/{$created['id']}", ['name' => 'Developer Platform'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Developer Platform')
        ->assertJsonPath('data.code', 'DEVEX');

    // Moving re-draws the reporting line underneath it, so the depth follows.
    $engineering = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();

    $moved = $this->withToken($this->admin)
        ->postJson("/api/v1/departments/{$created['id']}/move", ['parent_id' => $engineering->id])
        ->assertOk()
        ->json('data');

    expect($moved['parent_id'])->toBe((string) $engineering->id)
        ->and($moved['depth'])->toBe($engineering->depth + 1);

    // And back out to the top, which is what `parent_id: null` means — a real
    // answer rather than a missing field, which is why the request rule is
    // `present, nullable` and not `sometimes`.
    $this->withToken($this->admin)
        ->postJson("/api/v1/departments/{$created['id']}/move", ['parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.depth', 0);
});

it('refuses a rename from somebody who may only read the tree', function (): void {
    // The assertion that would have caught the missing policy is this one's
    // twin: a manager is refused AND an admin is allowed. Only the pair says
    // the gate is doing its job rather than refusing everyone.
    $engineering = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();

    $this->withToken($this->manager)
        ->patchJson("/api/v1/departments/{$engineering->id}", ['name' => 'Renamed by a manager'])
        ->assertForbidden();

    expect($engineering->fresh()->name)->not->toBe('Renamed by a manager');
});

it('refuses a move that would make a department its own ancestor', function (): void {
    // The domain's refusal, not the policy's, and the reason the picker on the
    // screen does not pre-filter descendants: this is decided inside the
    // transaction that performs the move, where the row locks are.
    $engineering = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();

    $child = DepartmentModel::query()
        ->where('parent_id', $engineering->id)
        ->firstOrFail();

    $this->withToken($this->admin)
        ->postJson("/api/v1/departments/{$engineering->id}/move", ['parent_id' => $child->id])
        ->assertStatus(422);

    expect($engineering->fresh()->parent_id)->toBeNull();
});
