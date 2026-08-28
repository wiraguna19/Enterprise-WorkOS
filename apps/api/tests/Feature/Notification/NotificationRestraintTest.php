<?php

declare(strict_types=1);

use App\Modules\Notification\Application\Service\NotificationDispatcher;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The hard problem in notifications is restraint, not delivery (docs/12 §10).
 *
 * A system that notifies on everything gets muted, and a muted system means
 * missed approvals — at which point the notification layer has broken the
 * workflow it exists to serve. These four rules are the difference.
 */
beforeEach(function (): void {
    $this->acme = '01900000-0000-7000-8000-0000000000ac';
    $this->ahmad = '01900000-0000-7000-8000-000000000202';
    $this->sarah = '01900000-0000-7000-8000-000000000203';
    $this->item = '01900014-0000-7000-8000-000000000001';   // ENG-142

    app(TenantContext::class)->setFromSession(
        $this->acme, $this->sarah, '01900000-0000-7000-8000-000000000003',
    );

    $this->dispatcher = app(NotificationDispatcher::class);
});

it('never tells someone what they just did', function (): void {
    // The single fastest way to train a person to ignore the badge.
    $delivered = $this->dispatcher->dispatch(
        type: 'work.updated',
        subjectType: 'work_item',
        subjectId: $this->item,
        recipients: [$this->sarah, $this->ahmad],
    );

    expect($delivered)->toBe(1)
        ->and(DB::table('notifications')
            ->where('membership_id', $this->sarah)
            ->where('subject_id', $this->item)
            ->where('type', 'work.updated')
            ->exists())->toBeFalse();
});

it('is idempotent, so a retried job does not double-notify', function (): void {
    $args = ['work.updated', 'work_item', $this->item, [$this->ahmad], [], null, 'seed-1'];

    expect($this->dispatcher->dispatch(...$args))->toBe(1)
        ->and($this->dispatcher->dispatch(...$args))->toBe(0);
});

it('dedupes per recipient, not globally', function (): void {
    // A global dedupe key would silently drop everyone after the first person —
    // the kind of bug that looks like "notifications are flaky".
    $this->dispatcher->dispatch(
        'work.updated', 'work_item', $this->item,
        [$this->ahmad, '01900000-0000-7000-8000-000000000204'],
        [], null, 'seed-2',
    );

    expect(DB::table('notifications')->where('dedupe_key', 'like', '%seed-2%')->count())->toBe(2);
});

it('respects a preference to switch a type off', function (): void {
    DB::table('notification_preferences')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => $this->acme,
        'membership_id' => $this->ahmad,
        'type' => 'work.updated',
        'in_app' => false,
        'email' => false,
        'digest' => 'off',
    ]);

    expect($this->dispatcher->dispatch(
        'work.updated', 'work_item', $this->item, [$this->ahmad], [], null, 'seed-3',
    ))->toBe(0);
});

it('delivers the ones that cannot be muted anyway', function (): void {
    // Being asked to decide, and being told your work bounced, are not
    // optional: muting them lets someone silently become the bottleneck for
    // their whole team.
    // upsert, not insert: the seed already carries a preference row for this
    // pair, and (membership_id, type) is unique. The point of the test is that
    // the preference says "muted", not that it is brand new — and the id must
    // stay whatever it already was.
    DB::table('notification_preferences')->upsert(
        [[
            'id' => (string) new UuidV7,
            'organization_id' => $this->acme,
            'membership_id' => $this->ahmad,
            'type' => 'approval.requested',
            'in_app' => false,
            'email' => false,
            'digest' => 'off',
        ]],
        ['membership_id', 'type'],
        ['in_app', 'email', 'digest'],
    );

    expect($this->dispatcher->dispatch(
        'approval.requested', 'work_item', $this->item, [$this->ahmad], [], null, 'seed-4',
    ))->toBe(1);
});

it('snapshots what the notification is about, so it still reads correctly later', function (): void {
    $this->dispatcher->dispatch(
        'work.updated', 'work_item', $this->item, [$this->ahmad], [], null, 'seed-5',
    );

    $payload = json_decode((string) DB::table('notifications')
        ->where('dedupe_key', 'like', '%seed-5%')->value('payload'), true);

    // Rendering the inbox from live joins means a renamed item rewrites history,
    // and a deleted one leaves a blank row (docs/03 §5).
    expect($payload['reference'])->toBe('ENG-142')
        ->and($payload['title'])->not->toBeEmpty();
});

it('refuses to let email and a digest both be on', function (): void {
    // Getting the same thing twice, once immediately and once in the daily
    // roundup, is how people learn to filter the whole sender.
    expect(fn () => DB::table('notification_preferences')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => $this->acme,
        'membership_id' => $this->sarah,
        'type' => 'work.assigned',
        'in_app' => true,
        'email' => true,
        'digest' => 'daily',
    ]))->toThrow(QueryException::class);
});

it('counts unread without counting archived', function (): void {
    $this->dispatcher->dispatch(
        'work.updated', 'work_item', $this->item, [$this->ahmad], [], null, 'seed-6',
    );

    DB::table('notifications')->where('dedupe_key', 'like', '%seed-6%')
        ->update(['archived_at' => now()]);

    expect($this->dispatcher->counts($this->ahmad)['unread'])
        ->toBe(DB::table('notifications')
            ->where('membership_id', $this->ahmad)
            ->whereNull('read_at')->whereNull('archived_at')->count());
});
