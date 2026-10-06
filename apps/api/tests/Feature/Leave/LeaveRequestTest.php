<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Asking for time off, and deciding (ADR 0063, slice 2).
 *
 * Dates are fixed: a balance depends on today, the hire date and the period,
 * and a test that used the real calendar would change its answer on 1
 * January. 2 March 2026 is a Monday.
 */
const LR_SARAH = '01900000-0000-7000-8000-000000000203';
const LR_LISA = '01900000-0000-7000-8000-000000000207';

beforeEach(function (): void {
    $this->travelTo('2026-03-02 09:00:00');

    $this->rina = $this->loginAs('rina@acme.test');
    $this->sarah = $this->loginAs('sarah@acme.test');
    $this->ahmad = $this->loginAs('ahmad@acme.test');

    $this->withToken($this->rina)->postJson('/api/v1/leave/settings/preset', ['preset' => 'indonesia'])->assertOk();

    // Sarah has been here since January 2024: past probation, a full 2025
    // behind her, and 2026's quota upfront.
    DB::table('employee_profiles')->where('membership_id', LR_SARAH)->update(['hired_at' => '2024-01-15']);
});

/** @return array<string, mixed> */
function leaveAsk(string $type, string $from, string $to, ?string $half = null): array
{
    $id = DB::table('leave_types')
        ->where('organization_id', '01900000-0000-7000-8000-0000000000ac')
        ->where('key', $type)
        ->value('id');

    return ['leave_type_id' => $id, 'starts_on' => $from, 'ends_on' => $to, 'half_day' => $half];
}

it('counts working days, skipping the weekend and the holidays', function (): void {
    $this->withToken($this->rina)
        ->postJson('/api/v1/leave/holidays', ['on_date' => '2026-03-11', 'name' => 'Libur', 'kind' => 'public'])
        ->assertCreated();

    // Mon 9 – Sun 15 March: five working days, one of them a holiday.
    $this->withToken($this->sarah)
        ->getJson('/api/v1/leave/quote?'.http_build_query(leaveAsk('annual', '2026-03-09', '2026-03-15')))
        ->assertOk()
        ->assertJsonPath('data.days', 4)
        ->assertJsonPath('data.uses_quota', true);

    $this->withToken($this->sarah)
        ->getJson('/api/v1/leave/quote?'.http_build_query(leaveAsk('annual', '2026-03-10', '2026-03-10', 'am')))
        ->assertOk()
        ->assertJsonPath('data.days', 0.5);
});

it('gives the year\'s quota and what was carried from last year', function (): void {
    $this->withToken($this->sarah)
        ->getJson('/api/v1/leave/me')
        ->assertOk()
        ->assertJsonPath('data.balance.entitlement', 12)
        ->assertJsonPath('data.balance.earned', 12)
        ->assertJsonPath('data.balance.carried', 5)
        ->assertJsonPath('data.balance.carry_expires_on', '2026-06-30')
        ->assertJsonPath('data.balance.available', 17);
});

it('earns a new joiner a day a month, and keeps quota leave until probation ends', function (): void {
    DB::table('employee_profiles')->where('membership_id', LR_LISA)->update(['hired_at' => '2026-02-01']);
    $lisa = $this->loginAs('lisa@acme.test');

    $this->withToken($lisa)
        ->getJson('/api/v1/leave/me')
        ->assertJsonPath('data.balance.earned', 2)
        ->assertJsonPath('data.balance.probation_ends_on', '2026-05-01');

    $this->withToken($lisa)
        ->postJson('/api/v1/leave/requests', leaveAsk('annual', '2026-03-16', '2026-03-16'))
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'in_probation');

    // Sick leave is not held back by probation.
    $this->withToken($lisa)
        ->postJson('/api/v1/leave/requests', leaveAsk('sick', '2026-03-03', '2026-03-03'))
        ->assertCreated();
});

it('goes to the manager, who approves it, and the person is told', function (): void {
    $id = $this->withToken($this->sarah)
        ->postJson('/api/v1/leave/requests', [...leaveAsk('annual', '2026-03-09', '2026-03-13'), 'reason' => 'Family visit'])
        ->assertCreated()
        ->json('data.id');

    $waiting = collect($this->withToken($this->ahmad)->getJson('/api/v1/leave/awaiting')->assertOk()->json('data'))
        ->firstWhere('id', $id);

    expect($waiting)->not->toBeNull()
        ->and($waiting['reason'])->toBe('Family visit')
        ->and($waiting['step'])->toBe('manager')
        ->and($waiting['balance']['pending'])->toEqual(5);

    $this->withToken($this->ahmad)->postJson("/api/v1/leave/requests/{$id}/approve")->assertNoContent();

    expect(DB::table('leave_requests')->where('id', $id)->value('status'))->toBe('approved')
        ->and(DB::table('notifications')->where('membership_id', LR_SARAH)->where('type', 'leave.approved')->exists())->toBeTrue();

    $this->withToken($this->sarah)
        ->getJson('/api/v1/leave/me')
        ->assertJsonPath('data.balance.used', 5)
        ->assertJsonPath('data.balance.available', 12);
});

it('refuses more days than are available, and days already taken', function (): void {
    // 18 working days in March and April: more than 17.
    $this->withToken($this->sarah)
        ->postJson('/api/v1/leave/requests', leaveAsk('annual', '2026-03-09', '2026-04-01'))
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'not_enough');

    $this->withToken($this->sarah)->postJson('/api/v1/leave/requests', leaveAsk('annual', '2026-03-09', '2026-03-10'))->assertCreated();

    $this->withToken($this->sarah)
        ->postJson('/api/v1/leave/requests', leaveAsk('personal', '2026-03-10', '2026-03-10'))
        ->assertStatus(409)
        ->assertJsonPath('error.details.refusal', 'overlap');
});

it('lets only the manager or HR decide, never the person, and goes on to HR when the policy says so', function (): void {
    DB::table('leave_policies')->update(['approval' => 'manager_then_hr']);

    $id = $this->withToken($this->sarah)
        ->postJson('/api/v1/leave/requests', leaveAsk('annual', '2026-03-09', '2026-03-09'))
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->sarah)->postJson("/api/v1/leave/requests/{$id}/approve")
        ->assertForbidden()->assertJsonPath('error.details.refusal', 'own_request');

    $this->withToken($this->loginAs('budi@acme.test'))->postJson("/api/v1/leave/requests/{$id}/approve")
        ->assertForbidden()->assertJsonPath('error.details.refusal', 'not_your_step');

    $this->withToken($this->ahmad)->postJson("/api/v1/leave/requests/{$id}/approve")->assertNoContent();

    expect(DB::table('leave_requests')->where('id', $id)->first(['status', 'step']))
        ->status->toBe('pending')
        ->step->toBe('hr');

    $this->withToken($this->rina)->postJson("/api/v1/leave/requests/{$id}/approve", ['note' => 'Enjoy'])->assertNoContent();

    expect(DB::table('leave_requests')->where('id', $id)->value('status'))->toBe('approved');
});

it('is withdrawn by its person before it starts, and corrected by HR after', function (): void {
    $id = $this->withToken($this->sarah)
        ->postJson('/api/v1/leave/requests', leaveAsk('annual', '2026-03-03', '2026-03-03'))
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->ahmad)->postJson("/api/v1/leave/requests/{$id}/approve")->assertNoContent();

    $this->travelTo('2026-03-04 09:00:00');

    $this->withToken($this->sarah)->postJson("/api/v1/leave/requests/{$id}/cancel")
        ->assertStatus(409)->assertJsonPath('error.details.refusal', 'not_cancellable');

    $this->withToken($this->rina)->postJson("/api/v1/leave/requests/{$id}/cancel")->assertNoContent();

    expect(DB::table('leave_requests')->where('id', $id)->value('status'))->toBe('cancelled');
});

it('shows every request to HR and to nobody else', function (): void {
    $this->withToken($this->sarah)->postJson('/api/v1/leave/requests', leaveAsk('sick', '2026-03-03', '2026-03-03'))->assertCreated();

    $this->withToken($this->sarah)->getJson('/api/v1/leave/requests')->assertForbidden();

    expect($this->withToken($this->rina)->getJson('/api/v1/leave/requests')->assertOk()->json('data'))->toHaveCount(1);
});
