<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Authority over ONE thing (docs/06 §2, ADR 0016).
 *
 * `scoped_role_assignments` and `PermissionResolver::hasOnScope()` shipped in
 * Phase 1, described in the migration as unused by design so that Phase 7 would
 * be "a feature, not a migration of live permission data". Nothing wrote a row
 * for six phases, so two policies recorded a refusal in their docblocks instead:
 * `TeamPolicy` would not let a lead manage their own team, `DepartmentPolicy`
 * would not let a head rename their own department, both because `if (lead)` is
 * the hardcoded role check docs/06 §2 rules out by name.
 *
 * Sarah is the lead of Frontend in the seed and an Employee — the exact person
 * those two refusals were about.
 *
 * Ids are written out rather than named by constant: Pest loads every test file
 * into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // role.manage
    $this->manager = $this->loginAs('ahmad@acme.test');   // manager, org-wide
    $this->sarah = $this->loginAs('sarah@acme.test');     // Employee, leads Frontend

    $this->sarahId = '01900000-0000-7000-8000-000000000203';
    $this->ahmadId = '01900000-0000-7000-8000-000000000202';
    $this->frontend = '01900000-0000-7000-8000-000000000801';
    $this->backend = '01900000-0000-7000-8000-000000000802';
    $this->engineering = '01900000-0000-7000-8000-000000000601';
    $this->marketing = '01900000-0000-7000-8000-000000000602';

    $this->tono = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'tono@acme.test')
        ->value('m.id');
});

it('refuses the lead her own team until somebody grants it', function (): void {
    // The refusal both policies recorded, reproduced: leading a team is a
    // relationship, and this product deliberately does not read authority from
    // one.
    $this->withToken($this->sarah)
        ->postJson("/api/v1/teams/{$this->frontend}/members", [
            'membership_id' => $this->tono,
        ])
        ->assertForbidden();

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(201);

    // Immediately, in the same process. The permission cache is versioned
    // rather than deleted, and a grant that took fifteen minutes to apply would
    // read as a grant that did nothing.
    $this->withToken($this->sarah)
        ->postJson("/api/v1/teams/{$this->frontend}/members", [
            'membership_id' => $this->tono,
        ])
        ->assertOk();
});

it('grants authority over that team and no other', function (): void {
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(201);

    // The whole point of a SCOPE. A grant that leaked sideways would be an
    // org-wide role with extra steps.
    $this->withToken($this->sarah)
        ->postJson("/api/v1/teams/{$this->backend}/members", [
            'membership_id' => $this->tono,
        ])
        ->assertForbidden();
});

it('takes the authority back', function (): void {
    $roles = $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(201)
        ->json('data');

    $grant = $roles['scoped'][0]['id'];

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/people/{$this->sarahId}/roles/{$grant}")
        ->assertNoContent();

    $this->withToken($this->sarah)
        ->postJson("/api/v1/teams/{$this->frontend}/members", [
            'membership_id' => $this->tono,
        ])
        ->assertForbidden();
});

it('lets a department be administered by somebody granted it, and only that one', function (): void {
    // Scoping `org_admin` to one department is what "head of Engineering"
    // means here: everything that role can do, inside that department alone.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/roles", [
            'role' => 'org_admin',
            'scope_type' => 'department',
            'scope_id' => $this->engineering,
        ])
        ->assertStatus(201);

    $this->withToken($this->manager)
        ->patchJson("/api/v1/departments/{$this->engineering}", ['name' => 'Engineering & Platform'])
        ->assertOk();

    $this->withToken($this->manager)
        ->patchJson("/api/v1/departments/{$this->marketing}", ['name' => 'Growth'])
        ->assertForbidden();
});

it('still refuses somebody with no grant at all', function (): void {
    // The route's `permission:` gate was REMOVED from these four endpoints so a
    // scoped grant could reach the policy at all. This is the assertion that
    // says removing it opened nothing: the policy is stricter than the gate it
    // replaced, and an employee with no grant gets the same 403 as before.
    $this->withToken($this->sarah)
        ->patchJson("/api/v1/departments/{$this->engineering}", ['name' => 'Nope'])
        ->assertForbidden();
});

it('refuses a grant that would mean nothing', function (): void {
    // Ahmad is a manager across the organization already.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'already_organization_wide');

    // There is no foreign key on (scope_type, scope_id) — it is polymorphic —
    // so a typo'd id would otherwise store a grant that resolves to nothing and
    // reads on screen as a perfectly good one.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => '01900000-0000-7000-8000-0000000009ff',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'scope_not_found');
});

it('will not let an administrator widen their own authority', function (): void {
    // The shape of an escalation, and refused for the reason `deactivate`
    // refuses self. Another administrator can always do it for them.
    $rina = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'rina@acme.test')
        ->value('m.id');

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$rina}/roles", [
            'role' => 'org_admin',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertForbidden();
});

it('keeps a person\'s grants from somebody who may not read them', function (): void {
    // A person's grants name the projects, teams and departments they have
    // authority over — a map of the organization that `role.view` is trusted
    // with and an ordinary employee is not.
    $this->withToken($this->sarah)
        ->getJson("/api/v1/people/{$this->ahmadId}/roles")
        ->assertForbidden();

    $this->withToken($this->admin)
        ->getJson("/api/v1/people/{$this->ahmadId}/roles")
        ->assertOk()
        ->assertJsonPath('data.organization_wide.0.key', 'manager');
});

it('records who was given what, and when', function (): void {
    // "Who may do what, and since when" is the first question asked after an
    // incident, and `membership_roles` records a timestamp and nothing about
    // who granted it.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(201);

    $logged = DB::table('activity_logs')
        ->where('subject_type', 'membership')
        ->where('subject_id', $this->sarahId)
        ->where('verb', 'role_granted')
        ->exists();

    expect($logged)->toBeTrue();

    $granted = DB::table('scoped_role_assignments')
        ->where('membership_id', $this->sarahId)
        ->value('granted_by');

    expect($granted)->not->toBeNull();
});
