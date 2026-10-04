<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * KPIs about one person (ADR 0062, "Per person"): the reporting line decides
 * everything, and nothing lists them across people.
 *
 * The seed's reporting line:
 *   Rina (head of operations)
 *     └ Ahmad (engineering manager)
 *         └ Sarah, David, Maya, Budi
 *     └ Lisa, Tono
 */
const PK_SARAH = '01900000-0000-7000-8000-000000000203';
const PK_BUDI = '01900000-0000-7000-8000-000000000206';

beforeEach(function (): void {
    $this->ahmad = $this->loginAs('ahmad@acme.test');
    $this->sarah = $this->loginAs('sarah@acme.test');
});

/**
 * @param  array<string, mixed>  $input
 * @return TestResponse<Response>
 */
function setPersonKpi(string $token, string $membershipId, array $input = []): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/kpis', [
        'name' => 'Code reviews given',
        'subject_type' => 'person',
        'subject_id' => $membershipId,
        'source' => 'manual',
        'unit' => 'reviews',
        'direction' => 'higher',
        'target' => 8,
        'period' => 'month',
        ...$input,
    ]);
}

it('is set by the manager, reported by the person, and seen by both', function (): void {
    $id = setPersonKpi($this->ahmad, PK_SARAH)
        ->assertCreated()
        ->assertJsonPath('data.subject.name', 'Sarah Chen')
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonPath('data.can_record', false)
        ->json('data.id');

    $hers = $this->withToken($this->sarah)->getJson('/api/v1/people/'.PK_SARAH.'/kpis')
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->json('data');

    expect(array_column($hers, 'id'))->toBe([$id])
        ->and($hers[0]['can_record'])->toBeTrue()
        ->and($hers[0]['can_manage'])->toBeFalse();

    $this->withToken($this->sarah)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => now()->toDateString(), 'value' => 9, 'note' => 'Two big ones'])
        ->assertOk()
        ->assertJsonPath('data.current.status', 'on_track');

    // The manager sets it; the number is hers to report.
    $this->withToken($this->ahmad)->putJson("/api/v1/kpis/{$id}/entries", ['period_start' => now()->toDateString(), 'value' => 1])
        ->assertForbidden();
    $this->withToken($this->sarah)->patchJson("/api/v1/kpis/{$id}", ['target' => 1])->assertForbidden();
    $this->withToken($this->sarah)->deleteJson("/api/v1/kpis/{$id}")->assertForbidden();

    $this->withToken($this->ahmad)->getJson("/api/v1/kpis/{$id}")
        ->assertOk()
        ->assertJsonPath('data.current.value', 9);
});

it('is seen by anyone above in the reporting line, and by nobody else', function (): void {
    $id = setPersonKpi($this->ahmad, PK_SARAH)->json('data.id');

    $rina = $this->loginAs('rina@acme.test');    // above Ahmad, so above Sarah
    $lisa = $this->loginAs('lisa@acme.test');    // a colleague, with kpi.view
    $budi = $this->loginAs('budi@acme.test');    // Sarah's peer

    $this->withToken($rina)->getJson("/api/v1/kpis/{$id}")->assertOk();
    $this->withToken($rina)->getJson('/api/v1/people/'.PK_SARAH.'/kpis')->assertOk();

    foreach ([$lisa, $budi] as $outsider) {
        $this->withToken($outsider)->getJson("/api/v1/kpis/{$id}")->assertNotFound();
        $this->withToken($outsider)->getJson('/api/v1/people/'.PK_SARAH.'/kpis')->assertNotFound();
    }
});

it('is never listed across people', function (): void {
    $id = setPersonKpi($this->ahmad, PK_SARAH)->json('data.id');

    expect(array_column($this->withToken($this->ahmad)->getJson('/api/v1/kpis')->json('data'), 'id'))->not->toContain($id);

    $this->withToken($this->ahmad)->getJson('/api/v1/kpis?subject_type=person')->assertStatus(422);

    $subjects = $this->withToken($this->ahmad)->getJson('/api/v1/kpis/vocabulary')->json('data.subjects');
    expect(array_unique(array_column($subjects, 'type')))->not->toContain('person');
});

it('cannot be set by the person, or by a peer', function (): void {
    setPersonKpi($this->sarah, PK_SARAH)->assertForbidden();
    setPersonKpi($this->sarah, PK_BUDI)->assertForbidden();
});

it('can be computed from their own assigned work, with the same definitions', function (): void {
    $data = setPersonKpi($this->ahmad, PK_SARAH, [
        'name' => 'Items finished',
        'source' => 'throughput',
        'period' => 'quarter',
    ])->assertCreated()
        ->assertJsonPath('data.unit', 'items')
        ->assertJsonPath('data.can_record', false)
        ->json('data');

    foreach ($data['history'] as $period) {
        expect($period['value'])->toBeNumeric()->toBeGreaterThanOrEqual(0);
    }
});
