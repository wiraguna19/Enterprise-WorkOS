<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * An organization's leave rules (ADR 0063): its own, starting from a preset,
 * warned and never refused when it goes below one.
 */
const LV_ACME = '01900000-0000-7000-8000-0000000000ac';
const LV_SARAH = '01900000-0000-7000-8000-000000000203';
const LV_LISA = '01900000-0000-7000-8000-000000000207';
const LV_RINA = '01900000-0000-7000-8000-000000000201';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function leavePolicyInput(array $overrides = []): array
{
    return [
        'period' => 'calendar_year',
        'accrual' => 'monthly_first_year',
        'base_days' => 12,
        'probation_months' => 3,
        'carry_over_max_days' => 5,
        'carry_over_until_month' => 6,
        'approval' => 'manager',
        'working_days' => [1, 2, 3, 4, 5],
        'tenure_bonus' => [['years' => 5, 'days' => 4], ['years' => 3, 'days' => 2]],
        'level_bonus' => ['manager' => 2],
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->admin = $this->loginAs('rina@acme.test');
});

it('is administered by leave.manage alone', function (): void {
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/leave/settings')
        ->assertForbidden();

    $this->withToken($this->admin)
        ->getJson('/api/v1/leave/settings')
        ->assertOk()
        ->assertJsonPath('data.policy', null)
        ->assertJsonPath('data.presets', ['indonesia']);
});

it('starts from a preset, and applying it again changes nothing HR did', function (): void {
    $types = $this->withToken($this->admin)
        ->postJson('/api/v1/leave/settings/preset', ['preset' => 'indonesia'])
        ->assertOk()
        ->assertJsonPath('data.policy.base_days', 12)
        ->assertJsonPath('data.policy.working_days', [1, 2, 3, 4, 5])
        ->assertJsonPath('data.warnings', [])
        ->json('data.types');

    expect(collect($types)->pluck('key')->all())->toBe(['annual', 'sick', 'personal', 'maternity', 'paternity']);

    $personal = collect($types)->firstWhere('key', 'personal');

    $this->withToken($this->admin)
        ->patchJson("/api/v1/leave/types/{$personal['id']}", ['name' => 'Izin pribadi'])
        ->assertOk();

    $again = $this->withToken($this->admin)
        ->postJson('/api/v1/leave/settings/preset', ['preset' => 'indonesia'])
        ->assertOk()
        ->json('data.types');

    expect($again)->toHaveCount(5)
        ->and(collect($again)->firstWhere('key', 'personal')['name'])->toBe('Izin pribadi');
});

it('saves the organization\'s own rules, tenure bands in order', function (): void {
    $this->withToken($this->admin)
        ->putJson('/api/v1/leave/settings/policy', leavePolicyInput())
        ->assertOk()
        ->assertJsonPath('data.policy.tenure_bonus', [['years' => 3, 'days' => 2], ['years' => 5, 'days' => 4]])
        ->assertJsonPath('data.policy.level_bonus.manager', 2);
});

it('warns, and does not refuse, when the rules go below the preset', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/leave/settings/preset', ['preset' => 'indonesia'])->assertOk();

    $maternity = DB::table('leave_types')->where('organization_id', LV_ACME)->where('key', 'maternity')->value('id');

    $this->withToken($this->admin)
        ->patchJson("/api/v1/leave/types/{$maternity}", ['is_active' => false])
        ->assertOk();

    $warnings = $this->withToken($this->admin)
        ->putJson('/api/v1/leave/settings/policy', leavePolicyInput(['base_days' => 10]))
        ->assertOk()
        ->json('data.warnings');

    expect(collect($warnings)->pluck('code')->all())
        ->toContain('base_days_below_minimum')
        ->toContain('type_missing_maternity');
});

it('refuses a second type with the same key', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/leave/settings/preset', ['preset' => 'indonesia'])->assertOk();

    $this->withToken($this->admin)
        ->postJson('/api/v1/leave/types', [
            'key' => 'sick', 'name' => 'Sick again', 'paid' => true, 'uses_quota' => false,
            'after_probation' => false, 'day_basis' => 'working_days', 'allow_half_day' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'key_taken');
});

it('keeps holidays that anyone asking for leave can read', function (): void {
    $id = $this->withToken($this->admin)
        ->postJson('/api/v1/leave/holidays', ['on_date' => '2026-12-25', 'name' => 'Hari Raya Natal', 'kind' => 'public'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->admin)
        ->postJson('/api/v1/leave/holidays', ['on_date' => '2026-12-25', 'name' => 'Twice', 'kind' => 'public'])
        ->assertStatus(409);

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/leave/holidays?year=2026')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Hari Raya Natal');

    $this->withToken($this->admin)->deleteJson("/api/v1/leave/holidays/{$id}")->assertNoContent();
});

it('gives the HR role the leave rules without anything about work', function (): void {
    $hr = DB::table('roles')->where('organization_id', LV_ACME)->where('key', 'hr')->value('id');

    expect($hr)->not->toBeNull();

    actingWithinTenant(LV_ACME, fn () => DB::table('membership_roles')->insert([
        'organization_id' => LV_ACME, 'membership_id' => LV_LISA, 'role_id' => $hr,
    ]));

    $lisa = $this->loginAs('lisa@acme.test');

    $this->withToken($lisa)->getJson('/api/v1/leave/settings')->assertOk();
    $this->withToken($lisa)->postJson('/api/v1/roles', ['key' => 'x', 'name' => 'X', 'permissions' => []])->assertForbidden();
});

it('lets someone with person.update record employment, never their own', function (): void {
    $this->withToken($this->admin)
        ->patchJson('/api/v1/people/'.LV_SARAH.'/employment', ['job_level' => 'supervisor', 'hired_at' => '2022-03-01'])
        ->assertOk()
        ->assertJsonPath('data.job_level', 'supervisor');

    expect(DB::table('employee_profiles')->where('membership_id', LV_SARAH)->value('hired_at'))->toBe('2022-03-01');

    $this->withToken($this->admin)
        ->patchJson('/api/v1/people/'.LV_RINA.'/employment', ['job_level' => 'director'])
        ->assertForbidden();

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->patchJson('/api/v1/people/'.LV_LISA.'/employment', ['job_level' => 'director'])
        ->assertForbidden();
});
