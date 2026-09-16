<?php

declare(strict_types=1);

use App\Modules\Governance\Infrastructure\Console\EnsureLogPartitions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The append-only tables stop growing forever (ADR 0021).
 *
 * `EnsureLogPartitions` has created monthly partitions since Phase 1 and
 * nothing has ever dropped one — a gap ADR 0019 named and deliberately left
 * open, because a retention window is a legal question before it is a technical
 * one. It is answered in `config/governance.php` and enforced by dropping whole
 * partitions, never by deleting rows.
 */
it('makes every partitioned table declare how long it lives', function (): void {
    // The point of this test: a new partitioned table cannot be added without
    // somebody deciding its retention. Without it, the answer defaults to
    // "forever" silently — which is how these six got here.
    $partitioned = collect(DB::select(<<<'SQL'
        SELECT c.relname AS table_name
          FROM pg_class c
          JOIN pg_partitioned_table p ON p.partrelid = c.oid
         WHERE c.relkind = 'p'
    SQL))->pluck('table_name')->sort()->values()->all();

    /** @var array<string, int|null> $windows */
    $windows = config('governance.retention');

    expect(collect($windows)->keys()->sort()->values()->all())->toBe($partitioned);

    // `null` is a decision — "kept as long as the organization exists" — and an
    // absent key is not. Anything else is a typo that would prune monthly.
    foreach ($windows as $table => $months) {
        expect($months === null || (is_int($months) && $months > 0))
            ->toBeTrue("retention for {$table} must be a positive number of months or null");
    }
});

it('drops a partition whose whole range is past the window', function (): void {
    $months = (int) config('governance.retention.activity_logs');
    $old = now()->startOfMonth()->subMonths($months + 2);
    $partition = 'activity_logs_p'.$old->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE IF NOT EXISTS %s PARTITION OF activity_logs FOR VALUES FROM ('%s') TO ('%s');",
        $partition,
        $old->format('Y-m-d'),
        $old->copy()->addMonth()->format('Y-m-d'),
    ));

    $this->artisan('governance:prune-log-partitions')->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', $partition)->exists())->toBeFalse();
});

it('keeps this month, and keeps the month the window ends in', function (): void {
    $current = 'activity_logs_p'.now()->format('Y_m');

    $this->artisan('governance:prune-log-partitions')->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', $current)->exists())->toBeTrue();

    // The edge. A partition is dropped only when its ENTIRE range is older than
    // the window, so the one straddling the boundary survives — rows live up to
    // a month longer than the window says, which is the price of dropping
    // instead of deleting and is written down rather than trimmed with a
    // DELETE.
    $months = (int) config('governance.retention.activity_logs');
    $edge = now()->startOfMonth()->subMonths($months);
    $partition = 'activity_logs_p'.$edge->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE IF NOT EXISTS %s PARTITION OF activity_logs FOR VALUES FROM ('%s') TO ('%s');",
        $partition,
        $edge->format('Y-m-d'),
        $edge->copy()->addMonth()->format('Y-m-d'),
    ));

    $this->artisan('governance:prune-log-partitions')->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', $partition)->exists())->toBeTrue();
});

it('never prunes the tables that are business records', function (): void {
    // A transition is how a work item reached its state and the input to every
    // cycle-time figure the product reports; an approval decision is somebody's
    // recorded answer on a record that may still be live. Deleting either by
    // age would silently change history and reports rather than free space.
    $old = now()->startOfMonth()->subYears(5);
    $partition = 'work_item_transitions_p'.$old->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE IF NOT EXISTS %s PARTITION OF work_item_transitions FOR VALUES FROM ('%s') TO ('%s');",
        $partition,
        $old->format('Y-m-d'),
        $old->copy()->addMonth()->format('Y-m-d'),
    ));

    $this->artisan('governance:prune-log-partitions')->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', $partition)->exists())->toBeTrue();
});

it('never drops a DEFAULT partition', function (): void {
    // It has no range, so no cutoff can be past it — and it is the one
    // partition whose loss would take rows from every month at once.
    $this->artisan('governance:prune-log-partitions')->assertSuccessful();

    foreach (array_keys(config('governance.retention')) as $table) {
        expect(DB::table('pg_class')->where('relname', $table.'_default')->exists())
            ->toBeTrue("{$table}_default was dropped");
    }
});

it('drops nothing on a dry run', function (): void {
    $months = (int) config('governance.retention.notifications');
    $old = now()->startOfMonth()->subMonths($months + 3);
    $partition = 'notifications_p'.$old->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE IF NOT EXISTS %s PARTITION OF notifications FOR VALUES FROM ('%s') TO ('%s');",
        $partition,
        $old->format('Y-m-d'),
        $old->copy()->addMonth()->format('Y-m-d'),
    ));

    $this->artisan('governance:prune-log-partitions', ['--dry-run' => true])
        ->expectsOutputToContain('would drop '.$partition)
        ->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', $partition)->exists())->toBeTrue();
});

it('says out loud when rows are stranded in a default partition', function (): void {
    // If the partition maintenance ever stops, writes land in DEFAULT — which
    // works, and is why a default exists. But a default holds every month at
    // once, so no single drop can retire those rows and the retention promise
    // quietly stops being kept. Counting them is the difference between a gap
    // and a silence.
    $months = (int) config('governance.retention.activity_logs');
    $stranded = now()->startOfMonth()->subMonths($months + 4);

    $membership = DB::table('memberships as m')
        ->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'rina@acme.test')
        ->first(['m.id', 'm.organization_id']);

    DB::table('activity_logs_default')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => $membership?->organization_id,
        'subject_type' => 'membership',
        'subject_id' => $membership?->id,
        'verb' => 'probe',
        'occurred_at' => $stranded,
    ]);

    $this->artisan('governance:prune-log-partitions')
        ->expectsOutputToContain('cannot be dropped')
        ->assertSuccessful();
});

it('tells the audit screen where the record ends', function (): void {
    // An empty result for last March reads as "nothing happened in March" —
    // the most dangerous sentence an audit log can imply, and indistinguishable
    // from "March was dropped" unless the screen is told the floor.
    $token = $this->loginAs('rina@acme.test');

    $meta = $this->withToken($token)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->json('meta.retention');

    expect($meta['months'])->toBe((int) config('governance.retention.audit_logs'))
        ->and($meta['covers_since'])->not->toBeNull();
});

it('keeps the partition list and the retention list talking to each other', function (): void {
    // Two hand-written lists about the same six tables, in two files. The
    // column accessor is the only door into the first, so the second cannot
    // name a table the maintenance command has never heard of.
    foreach (array_keys(config('governance.retention')) as $table) {
        expect(EnsureLogPartitions::partitionColumn((string) $table))->not->toBeNull();
    }
});
