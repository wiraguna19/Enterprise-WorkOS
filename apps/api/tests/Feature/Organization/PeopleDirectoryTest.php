<?php

declare(strict_types=1);

/**
 * The directory, read by somebody who is not a manager.
 *
 * Every earlier test of this endpoint signed in as an admin or a manager, and
 * every one of them passed while the screen was returning 500 to ordinary
 * employees: `viewWorkload` short-circuits on `person.view_workload`, so only
 * a caller WITHOUT that permission reached the reporting-line walk — which
 * climbed the chain by lazy loading, and lazy loading is disabled outside
 * production.
 *
 * The lesson is the fixture, not the assertion: **a permission check with an
 * `||` in it has as many paths as it has operands**, and testing it as the
 * person who satisfies the first one tests nothing beyond it.
 */
it('lists people for an employee who manages nobody', function (): void {
    $employee = $this->loginAs('sarah@acme.test');

    $rows = $this->withToken($employee)
        ->getJson('/api/v1/people?limit=50')
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty();

    // The workload permission is answered, and answered honestly: an employee
    // may see their OWN workload and nobody else's.
    $self = collect($rows)->firstWhere('email', 'sarah@acme.test');
    $other = collect($rows)->firstWhere('email', 'budi@acme.test');

    expect($self['permissions']['view_workload'])->toBeTrue()
        ->and($other['permissions']['view_workload'])->toBeFalse();
});

it('lets a manager see the workload of somebody below them', function (): void {
    $manager = $this->loginAs('ahmad@acme.test');

    $rows = $this->withToken($manager)
        ->getJson('/api/v1/people?limit=50')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('permissions.view_workload')->filter())->not->toBeEmpty();
});
