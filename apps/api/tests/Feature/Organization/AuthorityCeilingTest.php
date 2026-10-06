<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Nobody hands out more than they hold (AuthorityCeiling).
 *
 * The seed's only holder of `role.manage` holds everything, so the ceiling is
 * shown with an access clerk: someone who administers roles and service
 * accounts and holds little else. Before this, a scoped grant and a service
 * account's role had no ceiling, and the clerk could hand out a manager's
 * permissions — to a colleague, or to an account whose token they then hold.
 */
beforeEach(function (): void {
    $acme = '01900000-0000-7000-8000-0000000000ac';
    $role = (string) new UuidV7;

    actingWithinTenant($acme, function () use ($acme, $role): void {
        DB::table('roles')->insert([
            'id' => $role,
            'organization_id' => $acme,
            'key' => 'access_clerk',
            'name' => 'Access clerk',
            'description' => 'Administers access and nothing else',
            'is_system' => false,
            'level' => 20,
        ]);

        DB::table('role_permissions')->insertUsing(
            ['role_id', 'permission_id'],
            DB::table('permissions')
                ->whereIn('key', ['role.view', 'role.manage', 'service_account.manage'])
                ->selectRaw('?::uuid, id', [$role]),
        );

        // Lisa, an employee, becomes the clerk as well.
        DB::table('membership_roles')->insert([
            'organization_id' => $acme,
            'membership_id' => '01900000-0000-7000-8000-000000000207',
            'role_id' => $role,
        ]);
    });

    $this->clerk = $this->loginAs('lisa@acme.test');
    $this->tono = (string) DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'tono@acme.test')
        ->value('m.id');
});

it('refuses a scoped grant of permissions the granter does not hold', function (): void {
    $this->withToken($this->clerk)
        ->postJson("/api/v1/people/{$this->tono}/roles", [
            'role' => 'manager',
            'scope_type' => 'team',
            'scope_id' => '01900000-0000-7000-8000-000000000801',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'beyond_your_own_authority');

    expect(DB::table('scoped_role_assignments')->where('membership_id', $this->tono)->exists())->toBeFalse();
});

it('refuses a service account a role beyond its maker', function (): void {
    $this->withToken($this->clerk)
        ->postJson('/api/v1/service-accounts', ['name' => 'Back door', 'role' => 'manager'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'service_account.beyond_your_own_authority');

    // Within what the clerk holds, it still works.
    $this->withToken($this->clerk)
        ->postJson('/api/v1/service-accounts', ['name' => 'Directory sync', 'role' => 'viewer'])
        ->assertStatus(201);
});
