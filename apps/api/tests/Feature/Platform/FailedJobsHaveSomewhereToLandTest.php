<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A queue that cannot record a failure loses it (ADR 0037).
 *
 * `config/queue.php` named `failed_jobs` from the day the framework was
 * installed and nothing ever created it. A job that exhausted its retries was
 * handed to the failure store, the store's INSERT failed, and the job vanished
 * — payload, exception and all. Rule evaluation, notifications and recurrence
 * materialising all run on that queue.
 *
 * This asks the question from the CONFIG rather than from a hard-coded name, so
 * moving the store — to another table, or to another connection — is caught
 * here instead of by somebody wondering where their jobs went.
 */
it('has the table its own configuration names', function (): void {
    $driver = (string) config('queue.failed.driver');

    if (! str_starts_with($driver, 'database')) {
        $this->markTestSkipped("The failure store is `{$driver}`, not a database table.");
    }

    $table = (string) config('queue.failed.table');
    $connection = config('queue.failed.database');

    expect(Schema::connection($connection)->hasTable($table))->toBeTrue(
        "config/queue.php stores failed jobs in `{$table}` and that table does not exist. ".
        'A job that exhausts its retries is handed to a store that throws, and the job — '.
        'payload, exception, timing — is lost.',
    );
});

it('accepts the row the framework writes', function (): void {
    // The columns are Laravel's, not ours: the framework writes these rows and
    // `queue:retry` reads them, so a shape that merely looks right is not
    // enough. Writing one proves the contract rather than describing it.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: something went wrong',
        'failed_at' => now(),
    ]);

    expect(DB::table('failed_jobs')->count())->toBe(1);
});
