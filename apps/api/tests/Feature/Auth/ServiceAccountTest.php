<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Members that are not people (ADR 0059).
 *
 * What these hold: a service account acts with the role it was given and in
 * its own name; its tokens cannot manage credentials or service accounts; it
 * is never offered as a colleague and never holds work; and switching it off
 * ends its tokens on their next request.
 */
beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');   // service_account.manage
    $this->manager = $this->loginAs('ahmad@acme.test'); // not
});

/** @return array{0: string, 1: string} the account's membership id and a token value */
function serviceAccountWithToken(string $admin, string $access = 'read_write', string $role = 'manager'): array
{
    $id = (string) test()->withToken($admin)
        ->postJson('/api/v1/service-accounts', ['name' => 'Warehouse integration', 'role' => $role])
        ->assertStatus(201)
        ->json('data.id');

    $token = (string) test()->withToken($admin)
        ->postJson("/api/v1/service-accounts/{$id}/tokens", [
            'name' => 'Nightly sync',
            'access' => $access,
            'expires_in_days' => 90,
        ])
        ->assertStatus(201)
        ->json('data.token');

    return [$id, $token];
}

it('acts with its role, in its own name', function (): void {
    [$id, $token] = serviceAccountWithToken($this->admin);

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Warehouse integration');

    $project = $this->withToken($token)->getJson('/api/v1/projects/ENG')->assertOk()->json('data.id');

    $reference = $this->withToken($token)->postJson('/api/v1/work-items', [
        'title' => 'Restock bay 4',
        'type' => 'task',
        'project_id' => $project,
    ])->assertCreated()->json('data.reference');

    $item = DB::table('work_items')->where('reference', $reference)->first(['id', 'created_by_membership_id']);

    // Filed under the integration, not under the administrator who made it —
    // the point of the account.
    expect($item->created_by_membership_id)->toBe($id)
        ->and(DB::table('activity_logs')->where('subject_id', $item->id)->value('actor_name_snapshot'))
        ->toBe('Warehouse integration');
});

it('cannot be signed in to', function (): void {
    serviceAccountWithToken($this->admin);

    $email = DB::table('users')->where('kind', 'service')->value('email');

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => ''])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'anything at all'])->assertUnauthorized();
});

it('keeps its tokens away from the routes that make tokens and accounts', function (): void {
    [$id, $token] = serviceAccountWithToken($this->admin);

    $this->withToken($token)->getJson('/api/v1/service-accounts')->assertForbidden();
    $this->withToken($token)
        ->postJson("/api/v1/service-accounts/{$id}/tokens", ['name' => 'Successor', 'access' => 'read_write', 'expires_in_days' => 365])
        ->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/me/api-tokens')->assertForbidden();
});

it('is never an administrator', function (): void {
    $this->withToken($this->admin)
        ->postJson('/api/v1/service-accounts', ['name' => 'Too much', 'role' => 'org_admin'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'service_account.role_too_powerful');
});

it('is not offered as a colleague, and cannot be given work', function (): void {
    [$id] = serviceAccountWithToken($this->admin);

    $people = collect($this->withToken($this->admin)->getJson('/api/v1/people?limit=100')->assertOk()->json('data'));

    expect($people->pluck('id'))->not->toContain($id);

    $this->withToken($this->admin)
        ->postJson('/api/v1/work-items/ENG-142/assign', ['membership_id' => $id])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'service_account.cannot_hold_work');
});

it('is not stopped by an organization that requires a second factor', function (): void {
    [, $token] = serviceAccountWithToken($this->admin);

    DB::table('organizations')->where('slug', 'acme')->update(['require_mfa' => true]);

    // A person without a factor is confined; an integration has no person to
    // enrol one, and its administrator already met the requirement.
    $this->withToken($token)->getJson('/api/v1/work-items?limit=1')->assertOk();
});

it('ends its tokens when it is switched off', function (): void {
    [$id, $token] = serviceAccountWithToken($this->admin);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->withToken($this->admin)->deleteJson("/api/v1/service-accounts/{$id}")->assertNoContent();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $listed = collect($this->withToken($this->admin)->getJson('/api/v1/service-accounts')->json('data'))
        ->firstWhere('id', $id);

    expect($listed['active'])->toBeFalse()->and($listed['tokens'])->toBe(0);
});

it('is an administrator\'s to make', function (): void {
    $this->withToken($this->manager)
        ->postJson('/api/v1/service-accounts', ['name' => 'Not mine to make', 'role' => 'employee'])
        ->assertForbidden();
});

it('is offered to a private project, and sees it once added', function (): void {
    // An employee-role account: nothing but project membership would show it
    // FIN, the seeded private project.
    [$id, $token] = serviceAccountWithToken($this->admin, role: 'employee');

    $this->withToken($token)->getJson('/api/v1/projects/FIN')->assertNotFound();

    $offered = collect($this->withToken($this->admin)
        ->getJson('/api/v1/projects/FIN/integrations')
        ->assertOk()
        ->json('data'));

    expect($offered->pluck('id'))->toContain($id)
        ->and($offered->firstWhere('id', $id)['name'])->toBe('Warehouse integration');

    $this->withToken($this->admin)
        ->postJson('/api/v1/projects/FIN/members', ['membership_id' => $id, 'role' => 'member'])
        ->assertStatus(201)
        ->assertJsonPath('data.subject', 'person')
        ->assertJsonPath('data.is_service', true);

    $this->withToken($token)->getJson('/api/v1/projects/FIN')->assertOk();
});

it('is offered only to somebody who may change who has access', function (): void {
    serviceAccountWithToken($this->admin);

    // Sarah may read ENG but not manage its access.
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/projects/ENG/integrations')
        ->assertForbidden();
});

it('is no longer offered once switched off', function (): void {
    [$id] = serviceAccountWithToken($this->admin);

    $this->withToken($this->admin)->deleteJson("/api/v1/service-accounts/{$id}")->assertNoContent();

    $offered = $this->withToken($this->admin)->getJson('/api/v1/projects/ENG/integrations')->json('data');

    expect(collect($offered)->pluck('id'))->not->toContain($id);
});
