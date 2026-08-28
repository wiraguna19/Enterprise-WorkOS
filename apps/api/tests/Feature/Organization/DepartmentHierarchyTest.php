<?php

declare(strict_types=1);

use App\Modules\Organization\Application\Service\DepartmentService;
use App\Modules\Organization\Domain\Exception\DepartmentCycle;
use App\Modules\Organization\Domain\Exception\DepartmentTooDeep;
use App\Modules\Organization\Infrastructure\Eloquent\DepartmentModel;

/**
 * The materialized path is derived state. These tests exist because derived
 * state is exactly what rots silently (docs/03 §2).
 */
beforeEach(function (): void {
    $this->token = $this->loginAs('rina@acme.test'); // org admin
});

it('lists the tree in depth-first order without a recursive query', function (): void {
    $response = $this->withToken($this->token)->getJson('/api/v1/departments')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();

    // Quality Assurance is nested under Engineering in the seed, so path
    // ordering must place it immediately after its parent.
    $engineering = array_search('Engineering', $names, strict: true);

    if ($engineering === false) {
        // Without this the failure below is about adding 1 to `false`, which
        // says nothing about the tree.
        throw new RuntimeException('Engineering is missing from the department tree entirely.');
    }

    expect($names[(int) $engineering + 1])->toBe('Quality Assurance');
});

it('builds the path from the parent on create', function (): void {
    $parent = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();

    $response = $this->withToken($this->token)->postJson('/api/v1/departments', [
        'name' => 'Platform',
        'code' => 'ENG-PLT',
        'parent_id' => $parent->id,
    ])->assertCreated();

    $created = DepartmentModel::query()->findOrFail($response->json('data.id'));

    expect($created->path)->toBe($parent->path.$created->id.'/')
        ->and($created->depth)->toBe($parent->depth + 1);
});

it('rewrites the whole subtree when a department moves', function (): void {
    $service = app(DepartmentService::class);

    $engineering = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();
    $marketing = DepartmentModel::query()->where('code', 'MKT')->firstOrFail();
    $qa = DepartmentModel::query()->where('code', 'ENG-QA')->firstOrFail();

    $service->move($engineering, $marketing->id);

    $qa->refresh();
    $engineering->refresh();

    expect($engineering->depth)->toBe(1)
        ->and($qa->depth)->toBe(2, 'the descendant depth must shift with its ancestor')
        ->and(str_starts_with($qa->path, $marketing->path))
        ->toBeTrue('the descendant path must be rewritten too');
});

it('refuses to move a department beneath its own descendant', function (): void {
    $service = app(DepartmentService::class);

    $engineering = DepartmentModel::query()->where('code', 'ENG')->firstOrFail();
    $qa = DepartmentModel::query()->where('code', 'ENG-QA')->firstOrFail();

    expect(fn () => $service->move($engineering, $qa->id))
        ->toThrow(DepartmentCycle::class);

    // And nothing was written: a rejected move must leave the tree untouched.
    expect($engineering->fresh()->parent_id)->toBeNull();
});

it('enforces the depth limit across a whole subtree', function (): void {
    $service = app(DepartmentService::class);
    $parentId = null;

    // Build a chain to the limit.
    foreach (range(0, 6) as $level) {
        $department = $service->create("Level {$level}", "LVL{$level}", $parentId);
        $parentId = $department->id;
    }

    expect(fn () => $service->create('Too deep', 'LVL7', $parentId))
        ->toThrow(DepartmentTooDeep::class);
});

it('scopes department code uniqueness to the tenant', function (): void {
    // Globex also has a department coded OPS. Acme creating one must not
    // collide with it — a global unique rule would leak the other tenant.
    $this->withToken($this->token)->postJson('/api/v1/departments', [
        'name' => 'Operations Support', 'code' => 'OPS-2',
    ])->assertCreated();

    $this->withToken($this->token)->postJson('/api/v1/departments', [
        'name' => 'Duplicate', 'code' => 'OPS-2',
    ])->assertStatus(422);
});

it('denies an employee the ability to create departments', function (): void {
    $employeeToken = $this->loginAs('sarah@acme.test');

    $this->withToken($employeeToken)->postJson('/api/v1/departments', [
        'name' => 'Shadow IT', 'code' => 'SHDW',
    ])->assertForbidden();
});

it('records an activity entry for structural changes', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/departments', [
        'name' => 'Legal', 'code' => 'LGL',
    ])->assertCreated();

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => 'department',
        'verb' => 'created',
    ]);
});
