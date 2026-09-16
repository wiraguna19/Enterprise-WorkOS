<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Roles a customer writes for themselves (ADR 0018).
 *
 * ADR 0016 recorded the gap these fill: "head of Engineering" had to be spelled
 * as `org_admin` scoped to a department, because org_admin was the only role
 * carrying `department.update` — a grant that drags every other permission into
 * that department with it.
 *
 * The test that matters most is the escalation one. Without it, `role.manage`
 * is not "administer roles" but "invent any authority in the catalogue and hand
 * it to somebody", and since granting to yourself is refused the escalation is
 * exactly one colleague long.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // role.manage, everything
    $this->manager = $this->loginAs('ahmad@acme.test');   // no role.manage
    $this->sarahId = '01900000-0000-7000-8000-000000000203';
    $this->engineering = '01900000-0000-7000-8000-000000000601';

    // Stamped: roles have no delete in this suite once somebody holds one.
    $this->key = 'dept_head_'.now()->getTimestampMs();
});

it('writes a role with exactly the permissions somebody meant', function (): void {
    $role = $this->withToken($this->admin)
        ->postJson('/api/v1/roles', [
            'key' => $this->key,
            'name' => 'Department Head',
            'description' => 'Runs one department and nothing else.',
            'permissions' => ['department.view', 'department.update', 'person.view'],
        ])
        ->assertStatus(201)
        ->json('data');

    expect($role['permissions'])->toEqual(['department.update', 'department.view', 'person.view'])
        ->and($role['is_system'])->toBeFalse()
        ->and($role['held_by'])->toBe(0);

    // And it is a real role: grantable, and scoped like any other.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => $this->key,
            'scope_type' => 'department',
            'scope_id' => $this->engineering,
        ])
        ->assertStatus(201);

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->patchJson("/api/v1/departments/{$this->engineering}", ['name' => 'Engineering & Platform'])
        ->assertOk();
});

it('refuses to put authority in a role that its author does not have', function (): void {
    // Ahmad is a manager: he has `team.manage_members` and not `role.manage`,
    // so he cannot reach this endpoint at all — which is the outer defence.
    $this->withToken($this->manager)
        ->postJson('/api/v1/roles', [
            'key' => $this->key,
            'name' => 'Anything',
            'permissions' => [],
        ])
        ->assertForbidden();

    // The inner one: somebody who CAN administer roles still cannot write a
    // permission they do not hold. Rina holds everything, so the refusal is
    // shown against a permission no role in this build has.
    $this->withToken($this->admin)
        ->postJson('/api/v1/roles', [
            'key' => $this->key,
            'name' => 'Impossible',
            'permissions' => ['department.view', 'nuclear.launch'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'beyond_your_own_authority');
});

it('refuses to change or delete the roles the product ships with', function (): void {
    // A customer who removes `organization.view` from org_admin locks the whole
    // organization out of its own settings with no way back in.
    $this->withToken($this->admin)
        ->patchJson('/api/v1/roles/org_admin', ['permissions' => []])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'role.change_refused')
        ->assertJsonPath('error.details.refusal', 'system_role');

    $this->withToken($this->admin)
        ->deleteJson('/api/v1/roles/employee')
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'system_role');
});

it('applies a changed permission set to the people holding it, immediately', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/roles', [
            'key' => $this->key,
            'name' => 'Department Head',
            'permissions' => ['department.view', 'department.update'],
        ])->assertStatus(201);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => $this->key,
            'scope_type' => 'department',
            'scope_id' => $this->engineering,
        ])->assertStatus(201);

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->patchJson("/api/v1/departments/{$this->engineering}", ['name' => 'Engineering One'])
        ->assertOk();

    // Take the permission out of the role — not off her.
    $this->withToken($this->admin)
        ->patchJson("/api/v1/roles/{$this->key}", ['permissions' => ['department.view']])
        ->assertOk();

    // The permission cache is versioned per MEMBERSHIP, so there is no single
    // key to drop: every holder has to be bumped. A role nobody bumped is a
    // permission change that applies in fifteen minutes, to some people,
    // depending on when they last made a request.
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->patchJson("/api/v1/departments/{$this->engineering}", ['name' => 'Engineering Two'])
        ->assertForbidden();
});

it('refuses to delete a role people are holding', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/roles', [
            'key' => $this->key,
            'name' => 'Department Head',
            'permissions' => ['department.view'],
        ])->assertStatus(201);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => $this->key,
            'scope_type' => 'department',
            'scope_id' => $this->engineering,
        ])->assertStatus(201);

    // Deleting would cascade the grant away and quietly reduce what she can do
    // — noticed a week later as "I used to be able to do this".
    $this->withToken($this->admin)
        ->deleteJson("/api/v1/roles/{$this->key}")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'role_in_use');

    $held = $this->withToken($this->admin)
        ->getJson("/api/v1/roles/{$this->key}")
        ->assertOk()
        ->json('data.held_by');

    expect($held)->toBe(1);
});

it('serves the permission catalogue rather than letting a form invent one', function (): void {
    $catalogue = $this->withToken($this->admin)
        ->getJson('/api/v1/permissions')
        ->assertOk()
        ->json('data');

    $keys = array_column($catalogue, 'key');
    $inDatabase = DB::table('permissions')->count();

    expect(count($keys))->toBe($inDatabase)
        ->and($keys)->toContain('department.update');
});

it('keeps role administration away from somebody who may only read roles', function (): void {
    $this->withToken($this->manager)
        ->getJson('/api/v1/permissions')
        ->assertForbidden();

    $this->withToken($this->manager)
        ->deleteJson('/api/v1/roles/employee')
        ->assertForbidden();
});
