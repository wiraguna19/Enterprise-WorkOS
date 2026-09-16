<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Taking one permission away from one person (ADR 0020).
 *
 * Grants union. Until now that was the whole model: every role a person held
 * added to what they could do, and the only way to stop them doing one thing
 * was to take away a role that carried twenty others. A denial subtracts, and
 * it beats every grant — including one made after it.
 *
 * Ids are written out rather than named by constant: Pest loads every test file
 * into one process, so file-scope constants collide across the suite.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');      // role.manage
    $this->manager = $this->loginAs('ahmad@acme.test');   // manager, org-wide
    $this->sarah = $this->loginAs('sarah@acme.test');     // Employee, leads Frontend

    $this->ahmadId = '01900000-0000-7000-8000-000000000202';
    $this->sarahId = '01900000-0000-7000-8000-000000000203';
    $this->frontend = '01900000-0000-7000-8000-000000000801';
    $this->backend = '01900000-0000-7000-8000-000000000802';

    $this->tono = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'tono@acme.test')
        ->value('m.id');
});

it('takes a permission away from somebody who holds it across the organization', function (): void {
    // Ahmad is a manager everywhere, so `person.invite` is his by role.
    $this->withToken($this->manager)
        ->postJson('/api/v1/people/invite', ['email' => 'before@acme.test', 'role' => 'employee'])
        ->assertStatus(201);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Hiring freeze — HR asked for one door.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.denials.0.permission', 'person.invite')
        ->assertJsonPath('data.organization_wide.0.key', 'manager');

    // Immediately, in the same process: the permission cache is versioned
    // rather than deleted, and a denial that took fifteen minutes to apply
    // would read as a denial that did nothing.
    $this->withToken($this->manager)
        ->postJson('/api/v1/people/invite', ['email' => 'after@acme.test', 'role' => 'employee'])
        ->assertForbidden();
});

it('takes it away on one thing and leaves it everywhere else', function (): void {
    // The scoped half. `hasOnScope()` answers the org-wide question first, so
    // this is the case a denial has to reach BEFORE that answer — otherwise a
    // manager keeps everything a scoped denial was written to stop.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'team.manage_members',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
            'reason' => 'Conflict of interest while Frontend reorganizes.',
        ])
        ->assertStatus(201);

    $this->withToken($this->manager)
        ->postJson("/api/v1/teams/{$this->frontend}/members", ['membership_id' => $this->tono])
        ->assertForbidden();

    $this->withToken($this->manager)
        ->postJson("/api/v1/teams/{$this->backend}/members", ['membership_id' => $this->tono])
        ->assertOk();
});

it('beats a grant made after it', function (): void {
    // The property that makes deny worth having. Sarah is an Employee who
    // leads Frontend and holds nothing on it.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/denials", [
            'permission' => 'team.manage_members',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
            'reason' => 'Under review.',
        ])
        ->assertStatus(201);

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->sarahId}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => $this->frontend,
        ])
        ->assertStatus(201);

    $this->withToken($this->sarah)
        ->postJson("/api/v1/teams/{$this->frontend}/members", ['membership_id' => $this->tono])
        ->assertForbidden();
});

it('gives it back', function (): void {
    $denials = $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Hiring freeze.',
        ])
        ->assertStatus(201)
        ->json('data.denials');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/people/{$this->ahmadId}/denials/{$denials[0]['id']}")
        ->assertNoContent();

    $this->withToken($this->manager)
        ->postJson('/api/v1/people/invite', ['email' => 'thawed@acme.test', 'role' => 'employee'])
        ->assertStatus(201);
});

it('refuses a denial that names nothing', function (): void {
    // There is no foreign key from `permission_denials` to `permissions` — the
    // key is stored as text so a denial survives a permission being renamed —
    // so a typo would otherwise store a row that subtracts nothing and reads on
    // screen as a perfectly good denial.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invit',
            'reason' => 'Typo.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'unknown_permission');

    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'team.manage_members',
            'scope_type' => 'team',
            'scope_id' => '01900000-0000-7000-8000-0000000009ff',
            'reason' => 'Wrong id.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'scope_not_found');
});

it('insists on a reason', function (): void {
    // The only required free text in this module. A denial outlives the
    // incident that caused it, and an entry with no reason is a mystery to
    // whoever reads it six months later — including whoever wrote it.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", ['permission' => 'person.invite'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});

it('will not let an administrator deny themselves', function (): void {
    $rina = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'rina@acme.test')
        ->value('m.id');

    // The same refusal `grant` makes, for the same reason — and the reason
    // there is no lockout guard in the service: the person writing a denial is
    // never its subject, and holds `role.manage` org-wide, so there is always
    // somebody left who can lift it.
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$rina}/denials", [
            'permission' => 'role.manage',
            'reason' => 'Taking myself out of the loop.',
        ])
        ->assertForbidden();
});

it('will not let one person\'s denial be lifted through another', function (): void {
    $denials = $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Hiring freeze.',
        ])
        ->assertStatus(201)
        ->json('data.denials');

    $this->withToken($this->admin)
        ->deleteJson("/api/v1/people/{$this->sarahId}/denials/{$denials[0]['id']}")
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'denial_not_found');
});

it('says why somebody cannot do a thing', function (): void {
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Hiring freeze — HR asked for one door.',
        ])
        ->assertStatus(201);

    // Without this the refusal is a wall with no sign on it: the answer has to
    // name BOTH halves — the role that grants it and the denial that wins —
    // or an administrator cannot tell a permission never granted from one
    // taken away.
    $this->withToken($this->admin)
        ->getJson("/api/v1/people/{$this->ahmadId}/permissions/explain?permission=person.invite")
        ->assertOk()
        ->assertJsonPath('data.allowed', false)
        ->assertJsonPath('data.granted_by.0', 'Manager')
        ->assertJsonPath('data.denied_by.0.reason', 'Hiring freeze — HR asked for one door.');
});

it('explains a permission nobody took away', function (): void {
    $this->withToken($this->admin)
        ->getJson("/api/v1/people/{$this->ahmadId}/permissions/explain?permission=person.invite")
        ->assertOk()
        ->assertJsonPath('data.allowed', true)
        ->assertJsonPath('data.denied_by', []);

    // Asking about nothing is a sentence, not an explanation of the empty
    // string.
    $this->withToken($this->admin)
        ->getJson("/api/v1/people/{$this->ahmadId}/permissions/explain")
        ->assertStatus(422);
});

it('keeps both the denials and the reasons from somebody who may not read them', function (): void {
    // A reason is written by an administrator about a person, and reads like
    // it: "conflict of interest while Frontend reorganizes" is not a sentence
    // an ordinary employee should be able to fetch about a colleague.
    $this->withToken($this->sarah)
        ->getJson("/api/v1/people/{$this->ahmadId}/roles")
        ->assertForbidden();

    $this->withToken($this->sarah)
        ->getJson("/api/v1/people/{$this->ahmadId}/permissions/explain?permission=person.invite")
        ->assertForbidden();

    $this->withToken($this->sarah)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'I would rather he did not.',
        ])
        ->assertForbidden();
});

it('records who took what away, and why', function (): void {
    $this->withToken($this->admin)
        ->postJson("/api/v1/people/{$this->ahmadId}/denials", [
            'permission' => 'person.invite',
            'reason' => 'Hiring freeze.',
        ])
        ->assertStatus(201);

    $logged = DB::table('activity_logs')
        ->where('subject_type', 'membership')
        ->where('subject_id', $this->ahmadId)
        ->where('verb', 'permission_denied')
        ->exists();

    expect($logged)->toBeTrue();

    $deniedBy = DB::table('permission_denials')
        ->where('membership_id', $this->ahmadId)
        ->value('denied_by');

    expect($deniedBy)->not->toBeNull();
});
