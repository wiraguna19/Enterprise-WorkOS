<?php

declare(strict_types=1);

/**
 * A person's delivery without a KPI (ADR 0062, "Delivery without a KPI"):
 * items finished and the share on time, seen only along the reporting line,
 * and every figure the length of the list behind it.
 */
const PD_SARAH = '01900000-0000-7000-8000-000000000203';
const PD_DAVID = '01900000-0000-7000-8000-000000000204';

it('shows the person and the people above them, and nobody else', function (): void {
    foreach (['sarah@acme.test', 'ahmad@acme.test', 'rina@acme.test'] as $email) {
        $this->withToken($this->loginAs($email))
            ->getJson('/api/v1/people/'.PD_SARAH.'/delivery')
            ->assertOk()
            ->assertJsonCount(12, 'data.weeks');
    }

    foreach (['lisa@acme.test', 'budi@acme.test'] as $email) {
        $token = $this->loginAs($email);

        $this->withToken($token)->getJson('/api/v1/people/'.PD_SARAH.'/delivery')->assertNotFound();
        $this->withToken($token)
            ->getJson('/api/v1/people/'.PD_SARAH.'/delivery/items?from='.now()->subWeeks(4)->toDateString().'&to='.now()->toDateString())
            ->assertNotFound();
    }
});

it('reconciles every figure with the items behind it', function (): void {
    $ahmad = $this->loginAs('ahmad@acme.test');

    foreach ([PD_SARAH, PD_DAVID] as $person) {
        $data = $this->withToken($ahmad)->getJson("/api/v1/people/{$person}/delivery")->assertOk()->json('data');
        $summary = $data['summary'];

        $items = $this->withToken($ahmad)
            ->getJson("/api/v1/people/{$person}/delivery/items?from={$summary['from']}&to={$summary['to']}")
            ->assertOk()
            ->json('data');

        $dated = array_values(array_filter($items, fn (array $item): bool => $item['late'] !== null));
        $onTime = array_values(array_filter($dated, fn (array $item): bool => $item['late'] === false));

        expect($summary['finished'])->toBe(count($items))
            ->and($summary['dated'])->toBe(count($dated))
            ->and($summary['on_time'])->toBe(count($onTime))
            // The last four weeks of the trend are the summary.
            ->and(array_sum(array_column(array_slice($data['weeks'], -4), 'finished')))->toBe($summary['finished']);

        if ($summary['dated'] === 0) {
            expect($summary['on_time_rate'])->toBeNull();
        } else {
            expect($summary['on_time_rate'])->toEqualWithDelta($summary['on_time'] / $summary['dated'] * 100, 0.1);
        }
    }
});

it('refuses a window that ends before it starts', function (): void {
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->getJson('/api/v1/people/'.PD_SARAH.'/delivery/items?from=2026-09-10&to=2026-09-01')
        ->assertStatus(422);
});
