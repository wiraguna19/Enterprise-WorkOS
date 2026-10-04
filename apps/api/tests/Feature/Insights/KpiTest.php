<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Service\PermissionResolver;
use App\Modules\Insights\Application\Kpi\Kpis;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

/**
 * Key performance indicators for teams, departments and projects (ADR 0062).
 *
 *   Rina   — org admin    (kpi.view, kpi.manage)
 *   Ahmad  — manager      (kpi.view, kpi.manage)
 *   Sarah  — employee     (kpi.view), leads Frontend
 */
const KPI_ACME = '01900000-0000-7000-8000-0000000000ac';
const KPI_ENGINEERING = '01900000-0000-7000-8000-000000000601';
const KPI_FRONTEND = '01900000-0000-7000-8000-000000000801';
const KPI_BACKEND = '01900000-0000-7000-8000-000000000802';
const KPI_ENG_PROJECT = '01900003-0000-7000-8000-000000000001';

beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/**
 * @param  array<string, mixed>  $input
 * @return TestResponse<Response>
 */
function keepKpi(string $token, array $input): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/kpis', [
        'name' => 'Releases shipped',
        'subject_type' => 'team',
        'subject_id' => KPI_BACKEND,
        'source' => 'manual',
        'unit' => 'releases',
        'direction' => 'higher',
        'target' => 4,
        'period' => 'month',
        ...$input,
    ]);
}

it('keeps a manual KPI, takes its value for this period, and says how it is doing', function (): void {
    $id = keepKpi($this->ahmad, [])->assertCreated()
        ->assertJsonPath('data.current.status', 'no_data')
        ->assertJsonPath('data.current.partial', true)
        ->assertJsonCount(12, 'data.history')
        ->json('data.id');

    $today = now()->toDateString();

    $this->withToken($this->ahmad)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => $today, 'value' => 3.7, 'note' => 'One slipped'])
        ->assertOk()
        ->assertJsonPath('data.current.value', 3.7)
        ->assertJsonPath('data.current.status', 'at_risk')
        ->assertJsonPath('data.current.note', 'One slipped')
        ->assertJsonPath('data.current.period_start', now()->utc()->startOfMonth()->toDateString());

    // Recorded again for the same period: corrected, not added.
    $this->withToken($this->ahmad)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => $today, 'value' => 4])
        ->assertJsonPath('data.current.status', 'on_track');

    expect(DB::table('kpi_entries')->where('kpi_id', $id)->count())->toBe(1);
});

it('shows a group KPI to everyone who may see KPIs, and lets only a manager keep it', function (): void {
    $id = keepKpi($this->ahmad, [])->json('data.id');
    $sarah = $this->loginAs('sarah@acme.test');

    $list = $this->withToken($sarah)->getJson('/api/v1/kpis?subject_type=team&subject_id='.KPI_BACKEND)->assertOk()->json('data');

    expect(array_column($list, 'id'))->toContain($id)
        ->and($list[0]['can_manage'])->toBeFalse();

    $this->withToken($sarah)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => now()->toDateString(), 'value' => 1])
        ->assertForbidden();
    keepKpi($sarah, ['subject_id' => KPI_FRONTEND])->assertForbidden();

    $tono = $this->loginAs('tono@acme.test');   // viewer: no kpi.view
    $this->withToken($tono)->getJson('/api/v1/kpis')->assertForbidden();
});

it('lets kpi.manage granted on one team keep that team\'s KPIs only', function (): void {
    $sarah = $this->loginAs('sarah@acme.test');

    DB::table('scoped_role_assignments')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => KPI_ACME,
        'membership_id' => '01900000-0000-7000-8000-000000000203',
        'role_id' => '01900000-0000-7000-8000-000000000402',   // manager, which holds kpi.manage
        'scope_type' => 'team',
        'scope_id' => KPI_FRONTEND,
    ]);
    app(PermissionResolver::class)->invalidate('01900000-0000-7000-8000-000000000203');

    $subjects = $this->withToken($sarah)->getJson('/api/v1/kpis/vocabulary')->assertOk()->json('data.subjects');

    expect(array_column($subjects, 'name'))->toBe(['Frontend']);

    keepKpi($sarah, ['subject_id' => KPI_FRONTEND])->assertCreated();
    keepKpi($sarah, ['subject_id' => KPI_BACKEND])->assertForbidden();
});

it('computes a KPI from the work with the flow definitions, and fixes its unit and direction', function (): void {
    $project = keepKpi($this->ahmad, [
        'name' => 'Items finished',
        'subject_type' => 'project',
        'subject_id' => KPI_ENG_PROJECT,
        'source' => 'throughput',
        'unit' => 'whatever',
        'direction' => 'lower',
        'period' => 'quarter',
    ])->assertCreated()
        ->assertJsonPath('data.unit', 'items')
        ->assertJsonPath('data.direction', 'higher')
        ->json('data');

    // Engineering's projects include ENG, so its throughput cannot be smaller.
    $department = keepKpi($this->ahmad, [
        'name' => 'Engineering throughput',
        'subject_type' => 'department',
        'subject_id' => KPI_ENGINEERING,
        'source' => 'throughput',
        'period' => 'quarter',
    ])->json('data');

    foreach ($project['history'] as $i => $period) {
        expect($period['value'])->toBeNumeric()
            ->and($department['history'][$i]['value'])->toBeGreaterThanOrEqual($period['value']);
    }

    $onTime = keepKpi($this->ahmad, [
        'name' => 'On time',
        'subject_type' => 'project',
        'subject_id' => KPI_ENG_PROJECT,
        'source' => 'on_time_rate',
        'target' => 90,
        'period' => 'quarter',
    ])->assertJsonPath('data.unit', 'percent')->json('data');

    foreach ($onTime['history'] as $period) {
        expect($period['value'] === null || ($period['value'] >= 0 && $period['value'] <= 100))->toBeTrue();
    }

    // Nothing to enter for a computed KPI, and its definition is not the creator's.
    $this->withToken($this->ahmad)->putJson("/api/v1/kpis/{$project['id']}/entries", ['period_start' => now()->toDateString(), 'value' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.details.refusal', 'computed');
    $this->withToken($this->ahmad)->patchJson("/api/v1/kpis/{$project['id']}", ['direction' => 'lower'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.refusal', 'computed_definition');
});

it('refuses a period that has not started, and a change that would rewrite the history', function (): void {
    $id = keepKpi($this->ahmad, ['period' => 'week'])->json('data.id');

    $this->withToken($this->ahmad)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => now()->addWeeks(2)->toDateString(), 'value' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.details.refusal', 'future_period');

    foreach (['period' => 'month', 'source' => 'throughput', 'subject_type' => 'project'] as $field => $value) {
        $this->withToken($this->ahmad)->patchJson("/api/v1/kpis/{$id}", [$field => $value])->assertStatus(422);
    }

    $this->withToken($this->ahmad)->patchJson("/api/v1/kpis/{$id}", ['target' => 6, 'name' => 'Releases out'])
        ->assertOk()
        ->assertJsonPath('data.target', 6)
        ->assertJsonPath('data.name', 'Releases out');
});

it('archives rather than deletes, and an archived KPI leaves the list', function (): void {
    $id = keepKpi($this->ahmad, [])->json('data.id');

    $this->withToken($this->ahmad)->deleteJson("/api/v1/kpis/{$id}")->assertNoContent();

    expect(array_column($this->withToken($this->ahmad)->getJson('/api/v1/kpis')->json('data'), 'id'))->not->toContain($id)
        ->and(DB::table('kpis')->where('id', $id)->whereNotNull('archived_at')->exists())->toBeTrue();

    $this->withToken($this->ahmad)->patchJson("/api/v1/kpis/{$id}", ['target' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.details.refusal', 'archived');
});

it('words the status against the target, in either direction', function (): void {
    expect(Kpis::status(null, 10, 'higher'))->toBe('no_data')
        ->and(Kpis::status(10, 10, 'higher'))->toBe('on_track')
        ->and(Kpis::status(9, 10, 'higher'))->toBe('at_risk')
        ->and(Kpis::status(8.9, 10, 'higher'))->toBe('off_track')
        ->and(Kpis::status(40, 40, 'lower'))->toBe('on_track')
        ->and(Kpis::status(44, 40, 'lower'))->toBe('at_risk')
        ->and(Kpis::status(44.5, 40, 'lower'))->toBe('off_track');
});
