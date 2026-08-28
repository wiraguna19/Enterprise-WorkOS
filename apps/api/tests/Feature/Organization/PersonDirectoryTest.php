<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The directory and the profile (docs/08 §2).
 *
 * A people endpoint is where a product leaks HR data by accident: everyone in
 * the organization can read it, so anything that ends up in the payload ends up
 * visible to everyone. These tests are mostly about the fields that must NOT be
 * in a response, which is why they assert absence as often as presence.
 */
const RINA = '01900000-0000-7000-8000-000000000201';   // org admin
const AHMAD = '01900000-0000-7000-8000-000000000202';  // engineering manager
const SARAH = '01900000-0000-7000-8000-000000000203';  // reports to Ahmad
const GIL = '01900000-0000-7000-8000-000000000301';    // the other tenant

beforeEach(function (): void {
    $this->manager = $this->loginAs('ahmad@acme.test');
    $this->admin = $this->loginAs('rina@acme.test');
});

it('lists active people and leaves out the revoked', function (): void {
    $names = collect(
        $this->withToken($this->manager)->getJson('/api/v1/people?limit=100')
            ->assertOk()
            ->json('data')
    )->pluck('name');

    expect($names)->toContain('Sarah Chen')
        // Eko's membership is revoked. A directory that still lists him reads
        // as "still works here", which is a statement the data does not make.
        ->and($names)->not->toContain('Eko Prasetyo');
});

it('finds someone by part of their name', function (): void {
    $data = $this->withToken($this->manager)->getJson('/api/v1/people?q=chen')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Sarah Chen');
});

it('keeps the directory free of profile detail', function (): void {
    $person = collect(
        $this->withToken($this->admin)->getJson('/api/v1/people?limit=100')
            ->assertOk()
            ->json('data')
    )->firstWhere('id', SARAH);

    // Not merely absent from the UI — absent from the payload. An admin's
    // directory response is the one most likely to be logged or cached.
    expect($person)->not->toHaveKey('employee_number')
        ->and($person)->not->toHaveKey('roles')
        ->and($person)->not->toHaveKey('direct_reports');
});

it('answers the profile with the reporting line in both directions', function (): void {
    $profile = $this->withToken($this->manager)->getJson('/api/v1/people/'.AHMAD)
        ->assertOk()
        ->json('data');

    expect($profile['manager']['name'])->toBe('Rina Wijaya')
        // Linked by membership id, because that is what this endpoint takes.
        ->and($profile['manager']['id'])->toBe(RINA)
        ->and(collect($profile['direct_reports'])->pluck('name'))->toContain('Sarah Chen')
        ->and(collect($profile['roles'])->pluck('key')->all())->toBe(['manager']);
});

it('drops people who have left out of a reporting line', function (): void {
    // Eko reported to Ahmad before his membership was revoked.
    DB::table('employee_profiles')->insert([
        'id' => '01900000-0000-7000-8000-00000000c009',
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'membership_id' => '01900000-0000-7000-8000-000000000209',
        'job_title' => 'Former Developer',
        'manager_profile_id' => '01900000-0000-7000-8000-00000000c002',
        'employment_type' => 'full_time',
        'weekly_capacity_hours' => 40,
        'work_location' => 'Jakarta HQ',
    ]);

    $reports = collect(
        $this->withToken($this->manager)->getJson('/api/v1/people/'.AHMAD)
            ->assertOk()
            ->json('data.direct_reports')
    );

    // The row still exists — reporting lines are history — but a profile that
    // lists someone who left reads as stale data rather than as history.
    expect($reports->pluck('name'))->not->toContain('Eko Prasetyo');
});

it('shows the employee number only to someone who could change it', function (): void {
    // Ahmad manages Sarah, but managing is not editing: `person.update` is not
    // in the manager role, so her payroll number is not his to read.
    $asManager = $this->withToken($this->manager)->getJson('/api/v1/people/'.SARAH)
        ->assertOk()
        ->json('data');

    expect($asManager)->not->toHaveKey('employee_number');

    $asAdmin = $this->withToken($this->admin)->getJson('/api/v1/people/'.SARAH)
        ->assertOk()
        ->json('data');

    expect($asAdmin['employee_number'])->toBe('ACM-0003');
});

it('shows a person their own employee number', function (): void {
    // The policy lets anyone edit their own profile, and the field follows the
    // policy rather than a second, separate idea of who may see what.
    $self = $this->withToken($this->manager)->getJson('/api/v1/people/'.AHMAD)
        ->assertOk()
        ->json('data');

    expect($self['employee_number'])->toBe('ACM-0002');
});

it('cannot reach a person in another organization', function (): void {
    $response = $this->withToken($this->admin)->getJson('/api/v1/people/'.GIL);

    // 404, not 403: a 403 would confirm that the id names a real person
    // somewhere (docs/05 §3).
    $response->assertNotFound();
    expect($response->json('error.code'))->toBe('resource.not_found');
});
