<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Who may see, start and stop a recurring rule.
 *
 * A rule is a template of future work, so it is held to what that work would
 * be held to. Before this, anyone with `work_item.view` listed every rule in
 * the organization — private projects included — the calendar showed every
 * rule's title to everybody, a rule could aim at a project its author could not
 * see or at somebody else entirely, and anyone with `work_item.create` could
 * stop anybody's rule.
 */
const RA_ACME = '01900000-0000-7000-8000-0000000000ac';
const RA_FIN = '01900003-0000-7000-8000-000000000005';
const RA_ENG = '01900003-0000-7000-8000-000000000001';
const RA_AHMAD = '01900000-0000-7000-8000-000000000202';
const RA_SARAH = '01900000-0000-7000-8000-000000000203';
const RA_BUDI = '01900000-0000-7000-8000-000000000206';

/** @param array<string, mixed> $template */
function accessRule(string $title, array $template, string $author = RA_AHMAD): string
{
    $id = (string) new UuidV7;

    DB::table('recurrences')->insert([
        'id' => $id,
        'organization_id' => RA_ACME,
        'created_by_membership_id' => $author,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
        'template' => json_encode(['title' => $title, 'type' => 'task', ...$template], JSON_THROW_ON_ERROR),
        'next_run_at' => now()->addDay(),
        'created_at' => now()->subMonth(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('lists only the rules whose work the reader could see', function (): void {
    accessRule('Close the FIN ledger', ['project_id' => RA_FIN]);
    accessRule('Sweep the ENG board', ['project_id' => RA_ENG]);
    accessRule('Sarah files her timesheet', ['assignee_id' => RA_SARAH]);

    $titles = collect(
        $this->withToken($this->loginAs('sarah@acme.test'))
            ->getJson('/api/v1/recurrences')
            ->assertOk()
            ->json('data')
    )->pluck('template.title');

    expect($titles)->not->toContain('Close the FIN ledger')
        ->and($titles)->toContain('Sweep the ENG board')
        ->and($titles)->toContain('Sarah files her timesheet');
});

it('keeps a private rule off the calendar of someone who cannot see it', function (): void {
    accessRule('Close the FIN ledger', ['project_id' => RA_FIN]);

    $titles = collect(
        $this->withToken($this->loginAs('sarah@acme.test'))
            ->getJson('/api/v1/calendar?sources=recurring')
            ->assertOk()
            ->json('data')
    )->pluck('title');

    expect($titles)->not->toContain('Close the FIN ledger');
});

it('refuses a rule aimed at a project the author cannot see', function (): void {
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->postJson('/api/v1/recurrences', [
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            'template' => ['title' => 'Slipped into FIN', 'project_id' => RA_FIN],
        ])
        ->assertNotFound();

    expect(DB::table('recurrences')->where('template->title', 'Slipped into FIN')->exists())->toBeFalse();
});

it('refuses a rule that assigns somebody else without the right to assign', function (): void {
    $sarah = $this->loginAs('sarah@acme.test');

    $this->withToken($sarah)
        ->postJson('/api/v1/recurrences', [
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            'template' => ['title' => 'For Budi, every week', 'project_id' => RA_ENG, 'assignee_id' => RA_BUDI],
        ])
        ->assertForbidden();

    $this->withToken($sarah)
        ->postJson('/api/v1/recurrences', [
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO',
            'template' => ['title' => 'For me, every week', 'project_id' => RA_ENG, 'assignee_id' => RA_SARAH],
        ])
        ->assertCreated();
});

it('lets only the author, or someone who may assign, stop a rule', function (): void {
    // Visible to Sarah (its project is public to her) but not hers.
    $id = accessRule('Sweep the ENG board', ['project_id' => RA_ENG]);

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->deleteJson("/api/v1/recurrences/{$id}")
        ->assertForbidden()
        // In the API's own envelope, like every other refusal: a code to
        // branch on and the sentence, not the framework's bare message.
        ->assertJsonPath('error.code', 'auth.forbidden')
        ->assertJsonPath('error.message', 'Only its author, or someone who may assign work, can stop this recurring rule.');

    expect(DB::table('recurrences')->where('id', $id)->value('is_active'))->toBeTrue();

    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->deleteJson("/api/v1/recurrences/{$id}")
        ->assertNoContent();
});

it('does not admit to a rule the caller cannot see', function (): void {
    $id = accessRule('Close the FIN ledger', ['project_id' => RA_FIN]);

    $this->withToken($this->loginAs('sarah@acme.test'))
        ->deleteJson("/api/v1/recurrences/{$id}")
        ->assertNotFound();
});
