<?php

declare(strict_types=1);

/**
 * `filter`, `sort` and `include` are refused where an endpoint does not answer
 * them (docs/05 §4, RefuseUnansweredQuery).
 *
 * ADR 0039 closed the half where an endpoint filters and a KEY is unknown.
 * These hold the other half: an endpoint that filters nothing used to answer
 * `?filter[unread]=1` with its whole list and a 200 — a wrong answer that
 * looks exactly like a right one.
 */
beforeEach(function (): void {
    $this->token = $this->loginAs('ahmad@acme.test');
});

it('refuses a filter on an endpoint that filters nothing, and names the parameter', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/notifications?filter[unread]=1')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.filter.0', 'This endpoint takes no filter.');
});

it('refuses a sort where nothing sorts', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/me/work?sort=due_at')
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['sort']]]);
});

it('says what an endpoint DOES answer when it answers something else', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/projects?sort=name')
        ->assertUnprocessable()
        ->assertJsonPath('error.details.sort.0', 'This endpoint takes no sort. It answers: filter.');
});

it('refuses include everywhere, because nothing answers it yet', function (): void {
    // docs/05 §4 promises include and no endpoint accepts it. Until one does,
    // asking for it is a question with no answer, not an empty one.
    $this->withToken($this->token)
        ->getJson('/api/v1/work-items?include=assignees')
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['include']]]);
});

it('leaves the endpoints that do answer them answering', function (string $path): void {
    $this->withToken($this->token)->getJson($path)->assertOk();
})->with([
    'work items, filtered and sorted' => ['/api/v1/work-items?filter[priority]=high&sort=due_at&limit=5'],
    'projects, filtered' => ['/api/v1/projects?filter[status]=active'],
    'people, filtered' => ['/api/v1/people?filter[status]=active'],
    'teams, unfiltered' => ['/api/v1/teams'],
    'a list with no query at all' => ['/api/v1/notifications'],
]);
