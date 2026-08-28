<?php

declare(strict_types=1);

use App\Modules\Governance\Infrastructure\Console\EnsureLogPartitions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled commands exist, and the partition list is the real one.
 *
 * Both commands were scheduled in Phase 1 and never written:
 * `routes/console.php` named commands that did not exist, so the schedule
 * failed silently every night. Nothing caught it because nothing asserted it —
 * a scheduled command is the one kind of code that never runs in a test unless
 * a test asks it to.
 */
it('registers every command the schedule refers to', function (): void {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter()
        ->map(function (string $command): string {
            // Schedule::command() stores the full invocation ("'php' 'artisan'
            // name --flag"); the name is what the kernel knows it by.
            $parts = preg_split('/\s+/', str_replace("'", '', $command)) ?: [];
            $index = array_search('artisan', $parts, strict: true);

            return $index === false ? $command : (string) ($parts[$index + 1] ?? '');
        })
        ->filter()
        ->all();

    expect($scheduled)->not->toBeEmpty();

    $registered = array_keys(app(Kernel::class)->all());

    foreach ($scheduled as $command) {
        expect($registered)->toContain($command);
    }
});

it('knows about every partitioned table', function (): void {
    // The command carries a hand-written list. A new partitioned table that
    // forgets to add itself gets a DEFAULT partition and no error — which is
    // exactly the silence the command exists to end, so the list is checked
    // against the database rather than trusted.
    $partitioned = collect(DB::select(<<<'SQL'
        SELECT c.relname AS table_name
          FROM pg_class c
          JOIN pg_partitioned_table p ON p.partrelid = c.oid
         WHERE c.relkind = 'p'
    SQL))->pluck('table_name')->sort()->values()->all();

    /** @var array<string, string> $known */
    $known = (new ReflectionClass(EnsureLogPartitions::class))->getConstant('PARTITIONED');

    expect(collect($known)->keys()->sort()->values()->all())->toBe($partitioned);
});

it('builds partitions ahead of the calendar', function (): void {
    $ahead = now()->addMonths(3)->format('Y_m');

    DB::statement("DROP TABLE IF EXISTS activity_logs_p{$ahead}");

    $this->artisan('governance:ensure-log-partitions', ['--months' => 6])->assertSuccessful();

    expect(DB::table('pg_class')->where('relname', "activity_logs_p{$ahead}")->exists())->toBeTrue();
});

it('is safe to run twice', function (): void {
    // It runs monthly on a schedule that can overlap a deploy, and creating a
    // partition that overlaps an existing range is an error, not a no-op.
    $this->artisan('governance:ensure-log-partitions')->assertSuccessful();
    $this->artisan('governance:ensure-log-partitions')->assertSuccessful();
});

it('prunes sessions that can no longer authenticate anyone', function (): void {
    $sessionId = explode('|', $this->loginAs('sarah@acme.test'))[0];

    DB::table('sessions')->where('id', $sessionId)->update(['expires_at' => now()->subDay()]);

    $this->artisan('identity:prune-expired-sessions')->assertSuccessful();

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();
});

it('keeps a freshly revoked session long enough to explain itself', function (): void {
    $sessionId = explode('|', $this->loginAs('sarah@acme.test'))[0];

    DB::table('sessions')->where('id', $sessionId)->update(['revoked_at' => now()]);

    $this->artisan('identity:prune-expired-sessions')->assertSuccessful();

    // "I was logged out — when, and by what?" is asked in the days after, and
    // the row is the only place that answers it.
    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeTrue();
});
