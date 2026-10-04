<?php

declare(strict_types=1);

/**
 * The palette's memory of what a person searched for and opened.
 *
 * Their own only, newest first, the same search once however often it is
 * made, and never more than the service keeps.
 */
beforeEach(function (): void {
    $this->sarah = $this->loginAs('sarah@acme.test');
});

function recall(string $token): array
{
    return test()->withToken($token)
        ->getJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->json('data');
}

it('remembers a search, newest first, with the category it was narrowed to', function (): void {
    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'migration'])->assertNoContent();
    $this->travel(1)->seconds();
    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'Ahmad', 'type' => 'person'])->assertNoContent();

    $rows = recall($this->sarah);

    expect(array_column($rows, 'query'))->toBe(['Ahmad', 'migration'])
        ->and($rows[0]['type'])->toBe('person')
        ->and($rows[1]['type'])->toBeNull();
});

it('moves a repeated search to the top instead of listing it twice, whatever its case', function (): void {
    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'board'])->assertNoContent();
    $this->travel(1)->seconds();
    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'audit'])->assertNoContent();
    $this->travel(1)->seconds();
    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'Board'])->assertNoContent();

    expect(array_column(recall($this->sarah), 'query'))->toBe(['Board', 'audit']);
});

it('keeps only the newest ten', function (): void {
    foreach (range(1, 12) as $n) {
        $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => "search {$n}"])->assertNoContent();
        $this->travel(1)->seconds();
    }

    $queries = array_column(recall($this->sarah), 'query');

    expect($queries)->toHaveCount(10)
        ->and($queries[0])->toBe('search 12')
        ->and($queries)->not->toContain('search 1')
        ->and($queries)->not->toContain('search 2');
});

it('forgets one, or all, and only ever the caller\'s own', function (): void {
    $ahmad = $this->loginAs('ahmad@acme.test');

    $this->withToken($this->sarah)->postJson('/api/v1/me/recent-searches', ['query' => 'mine'])->assertNoContent();
    $this->withToken($ahmad)->postJson('/api/v1/me/recent-searches', ['query' => 'his'])->assertNoContent();

    $sarahs = recall($this->sarah);
    expect(array_column($sarahs, 'query'))->toBe(['mine']);

    // Ahmad cannot remove Sarah's by its id: it matches nothing of his.
    $this->withToken($ahmad)->deleteJson("/api/v1/me/recent-searches/{$sarahs[0]['id']}")->assertNoContent();
    expect(recall($this->sarah))->toHaveCount(1);

    $this->withToken($this->sarah)->deleteJson("/api/v1/me/recent-searches/{$sarahs[0]['id']}")->assertNoContent();
    expect(recall($this->sarah))->toBe([]);

    $this->withToken($ahmad)->deleteJson('/api/v1/me/recent-searches')->assertNoContent();
    expect(recall($ahmad))->toBe([]);
});

it('refuses a category search does not have', function (): void {
    $this->withToken($this->sarah)
        ->postJson('/api/v1/me/recent-searches', ['query' => 'anything', 'type' => 'invoice'])
        ->assertUnprocessable();
});
