<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Database;

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reports read from a replica, when there is one (docs/10 Phase 7, ADR 0053).
 *
 * docs/12 §1 paid for the modular monolith with "a heavy report competes with
 * interactive requests for the same PHP workers", and promised the mitigation:
 * reporting reads move to a replica before they become a problem. This is the
 * switch — and it is ONLY a switch: without `DB_REPORTING_HOST` it calls the
 * callback and changes nothing.
 *
 * ## Why the default connection moves, rather than every query naming one
 *
 * Insights is forty-odd queries across six query classes and five reports,
 * written as `DB::select()` and Eloquent against the default connection. Naming
 * a connection in each would be forty places to forget one — and the forgotten
 * one still works, against the primary, silently. Moving the default for the
 * length of one callback routes all of them, including the visibility rules and
 * policies they call into, and the next query written there is routed too.
 *
 * ## What must never run inside it
 *
 * A write. A replica refuses them, and the failure would arrive as a 500 from a
 * report. So the HTTP side only switches for safe methods, and the export job
 * switches for the build alone — its status row is written on the primary,
 * before and after.
 *
 * ## Row-Level Security comes along
 *
 * The tenant boundary (ADR 0051) is applied per CONNECTION — `SET ROLE` and
 * `app.organization_id` live in a database session — so the replica's session
 * is told which organization is in force every time the switch is thrown.
 * A hot standby accepts both: neither is a write.
 *
 * ## What it costs
 *
 * Replication lag. A report may be a few seconds behind the screen that
 * changed it; that is the trade docs/12 names, and it is why only reports and
 * insights move — a list somebody just edited must never read a replica.
 */
final class ReportingReplica
{
    public const CONNECTION = 'reporting';

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Build the `reporting` connection from the primary's settings, if a
     * replica host is configured. Called once, from the platform provider.
     */
    public static function defineConnection(): void
    {
        $host = config('database.reporting.host');

        if (! is_string($host) || $host === '') {
            return;
        }

        /** @var array<string, mixed> $primary */
        $primary = (array) config('database.connections.'.config('database.default'), []);

        $overrides = array_filter([
            'host' => $host,
            'port' => config('database.reporting.port'),
            'username' => config('database.reporting.username'),
            'password' => config('database.reporting.password'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        config(['database.connections.'.self::CONNECTION => [
            ...$primary,
            ...$overrides,
            // Visible in pg_stat_activity, so "is the replica actually being
            // used" is a query anybody can run rather than a belief.
            'application_name' => 'workos-reporting',
        ]]);
    }

    public function isConfigured(): bool
    {
        return is_array(config('database.connections.'.self::CONNECTION));
    }

    /**
     * Run a read-only callback against the replica — or, with none
     * configured, exactly as it would have run anyway.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $previous = DB::getDefaultConnection();

        if (! $this->isConfigured() || $previous === self::CONNECTION) {
            return $callback();
        }

        DB::setDefaultConnection(self::CONNECTION);

        try {
            // Now that the default is the replica, the boundary lands on it.
            $this->tenant->reassert();

            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
