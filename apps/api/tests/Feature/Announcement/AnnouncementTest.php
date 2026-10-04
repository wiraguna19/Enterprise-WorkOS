<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Service\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

/**
 * Announcements (ADR 0061): who may address which group, who is in a group,
 * and the difference between reading and acknowledging.
 *
 * The seed's structure is what these lean on:
 *   Rina   — org admin                       (announcement.publish)
 *   Ahmad  — manager, heads Engineering      (announcement.publish_own_group)
 *   Sarah  — employee, leads Frontend, profile in Engineering
 *   Budi   — employee, on Frontend
 *   Maya   — employee, profile in Quality Assurance (below Engineering)
 *   Lisa   — employee, Marketing
 */
const ANN_ENGINEERING = '01900000-0000-7000-8000-000000000601';
const ANN_MARKETING = '01900000-0000-7000-8000-000000000602';
const ANN_FRONTEND = '01900000-0000-7000-8000-000000000801';
const ANN_QA_TEAM = '01900000-0000-7000-8000-000000000803';

beforeEach(function (): void {
    $this->rina = $this->loginAs('rina@acme.test');
    $this->ahmad = $this->loginAs('ahmad@acme.test');
});

/**
 * @param  array<string, mixed>  $input
 * @return TestResponse<Response>
 */
function announce(string $token, array $input): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/announcements', [
        'title' => 'Office closed on Friday',
        'body' => "The office is closed on Friday.\nWork from home.",
        ...$input,
    ]);
}

/** @return list<string> */
function feedTitles(string $token): array
{
    return array_column(test()->withToken($token)->getJson('/api/v1/announcements')->assertOk()->json('data'), 'title');
}

it('says something to the whole organization, and tells everyone but the author', function (): void {
    $id = announce($this->rina, ['audience_type' => 'organization', 'title' => 'Town hall'])
        ->assertCreated()
        ->assertJsonPath('data.audience.type', 'organization')
        ->assertJsonPath('data.can_manage', true)
        ->json('data.id');

    $lisa = $this->loginAs('lisa@acme.test');

    expect(feedTitles($lisa))->toContain('Town hall');

    $types = array_column($this->withToken($lisa)->getJson('/api/v1/notifications')->json('data'), 'type');
    expect($types)->toContain('announcement.published');

    $mine = array_column($this->withToken($this->rina)->getJson('/api/v1/notifications')->json('data'), 'subject');
    expect(array_column($mine, 'id'))->not->toContain($id);
});

it('reaches a department and every department below it, and nobody else', function (): void {
    announce($this->ahmad, ['audience_type' => 'department', 'audience_id' => ANN_ENGINEERING, 'title' => 'Release freeze'])
        ->assertCreated();

    $maya = $this->loginAs('maya@acme.test');   // Quality Assurance, below Engineering
    $sarah = $this->loginAs('sarah@acme.test');
    $lisa = $this->loginAs('lisa@acme.test');   // Marketing

    expect(feedTitles($maya))->toContain('Release freeze')
        ->and(feedTitles($sarah))->toContain('Release freeze')
        ->and(feedTitles($lisa))->not->toContain('Release freeze');
});

it('lets a manager address the groups they run, and refuses the rest', function (): void {
    $audiences = $this->withToken($this->ahmad)->getJson('/api/v1/announcements/audiences')->assertOk()->json('data');
    $names = array_column($audiences, 'name');

    expect(array_column($audiences, 'type'))->not->toContain('organization')
        ->and($names)->toContain('Engineering', 'Quality Assurance', 'Frontend', 'QA')
        ->and($names)->not->toContain('Marketing');

    announce($this->ahmad, ['audience_type' => 'team', 'audience_id' => ANN_QA_TEAM])->assertCreated();
    announce($this->ahmad, ['audience_type' => 'organization'])->assertForbidden();
    announce($this->ahmad, ['audience_type' => 'department', 'audience_id' => ANN_MARKETING])->assertForbidden();
});

it('offers nothing to somebody who may address no group, and refuses them', function (): void {
    $sarah = $this->loginAs('sarah@acme.test');   // leads Frontend, but holds neither permission

    expect($this->withToken($sarah)->getJson('/api/v1/announcements/audiences')->json('data'))->toBe([]);

    announce($sarah, ['audience_type' => 'team', 'audience_id' => ANN_FRONTEND])
        ->assertForbidden();
});

it('lets a permission granted on one team speak to that team only', function (): void {
    $sarah = $this->loginAs('sarah@acme.test');

    // A role that holds `announcement.publish`, granted ON Frontend alone.
    DB::table('scoped_role_assignments')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'membership_id' => '01900000-0000-7000-8000-000000000203',
        'role_id' => '01900000-0000-7000-8000-000000000401',
        'scope_type' => 'team',
        'scope_id' => ANN_FRONTEND,
    ]);
    app(PermissionResolver::class)
        ->invalidate('01900000-0000-7000-8000-000000000203');

    expect(array_column($this->withToken($sarah)->getJson('/api/v1/announcements/audiences')->json('data'), 'name'))
        ->toBe(['Frontend']);

    announce($sarah, ['audience_type' => 'team', 'audience_id' => ANN_FRONTEND])->assertCreated();
    announce($sarah, ['audience_type' => 'team', 'audience_id' => ANN_QA_TEAM])->assertForbidden();
    announce($sarah, ['audience_type' => 'organization'])->assertForbidden();
});

it('tells reading apart from acknowledging, and names only who has not acknowledged', function (): void {
    $id = announce($this->rina, [
        'audience_type' => 'team',
        'audience_id' => ANN_FRONTEND,
        'requires_acknowledgement' => true,
    ])->assertCreated()->json('data.id');

    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)->getJson('/api/v1/announcements')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('meta.unread', 1);

    $this->withToken($sarah)->postJson('/api/v1/announcements/read', ['ids' => [$id]])
        ->assertJsonPath('data.marked', 1);

    $this->withToken($sarah)->getJson('/api/v1/announcements')
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.0.acknowledged', false)
        ->assertJsonPath('meta.unread', 0);

    $this->withToken($sarah)->postJson("/api/v1/announcements/{$id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true)
        ->assertJsonMissingPath('data.not_acknowledged');

    $detail = $this->withToken($this->rina)->getJson("/api/v1/announcements/{$id}")->assertOk()->json('data');

    expect($detail['stats'])->toBe(['audience' => 2, 'read' => 1, 'acknowledged' => 1])
        ->and(array_column($detail['not_acknowledged'], 'name'))->toBe(['Budi Santoso']);
});

it('refuses to acknowledge what does not ask for it', function (): void {
    $id = announce($this->rina, ['audience_type' => 'organization'])->json('data.id');

    $this->withToken($this->ahmad)->postJson("/api/v1/announcements/{$id}/acknowledge")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'announcement.refused');
});

it('hides what was said to another group', function (): void {
    $id = announce($this->rina, ['audience_type' => 'team', 'audience_id' => ANN_FRONTEND])->json('data.id');

    $lisa = $this->loginAs('lisa@acme.test');

    $this->withToken($lisa)->getJson("/api/v1/announcements/{$id}")->assertNotFound();
    $this->withToken($lisa)->postJson('/api/v1/announcements/read', ['ids' => [$id]])
        ->assertJsonPath('data.marked', 0);
});

it('takes an expired announcement off the feed and keeps it for whoever manages it', function (): void {
    $id = announce($this->rina, ['audience_type' => 'organization', 'title' => 'Short-lived', 'expires_at' => now()->addHour()->toIso8601String()])
        ->assertCreated()->json('data.id');

    $this->travel(2)->hours();

    expect(feedTitles($this->ahmad))->not->toContain('Short-lived');

    $managed = $this->withToken($this->rina)->getJson('/api/v1/announcements?manage=1')->assertOk()->json('data');
    $row = collect($managed)->firstWhere('id', $id);

    expect($row['expired'])->toBeTrue()
        ->and($row['stats']['audience'])->toBeGreaterThan(1);

    announce($this->rina, ['audience_type' => 'organization', 'expires_at' => now()->subDay()->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonPath('error.details.refusal', 'expires_before_published');
});

it('lets its author change or retract it, never its audience, and nobody else but an admin', function (): void {
    $id = announce($this->ahmad, ['audience_type' => 'team', 'audience_id' => ANN_FRONTEND])->json('data.id');
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)->patchJson("/api/v1/announcements/{$id}", ['title' => 'Hijacked'])->assertForbidden();

    $this->withToken($this->ahmad)->patchJson("/api/v1/announcements/{$id}", ['pinned' => true, 'title' => 'Office closed Friday'])
        ->assertOk()
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.title', 'Office closed Friday');

    $this->withToken($this->ahmad)->patchJson("/api/v1/announcements/{$id}", ['audience_type' => 'organization'])
        ->assertStatus(422);

    $this->withToken($this->rina)->patchJson("/api/v1/announcements/{$id}", ['pinned' => false])->assertOk();

    $this->withToken($this->ahmad)->deleteJson("/api/v1/announcements/{$id}")->assertNoContent();
    $this->withToken($this->ahmad)->getJson("/api/v1/announcements/{$id}")->assertNotFound();
});

it('puts pinned announcements first', function (): void {
    announce($this->rina, ['audience_type' => 'organization', 'title' => 'Pinned one', 'pinned' => true]);
    $this->travel(1)->seconds();
    announce($this->rina, ['audience_type' => 'organization', 'title' => 'Newer one']);

    expect(array_slice(feedTitles($this->ahmad), 0, 2))->toBe(['Pinned one', 'Newer one']);
});
