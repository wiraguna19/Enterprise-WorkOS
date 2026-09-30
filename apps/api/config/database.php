<?php

declare(strict_types=1);

/**
 * Only what this product adds to Laravel's database configuration; the
 * framework's own defaults (`default`, `connections.pgsql`, `redis`, …) are
 * merged underneath by the framework and are not repeated here.
 *
 * `reporting` names a read replica for reports and insights (docs/10 Phase 7,
 * ADR 0053). Unset — the default, and every environment this product has run
 * in so far — means there is no replica and everything reads the primary.
 * Set `DB_REPORTING_HOST` and a `reporting` connection is built from the
 * primary's settings with these overrides; see ReportingReplica.
 */
return [
    'reporting' => [
        'host' => env('DB_REPORTING_HOST'),
        'port' => env('DB_REPORTING_PORT'),
        'username' => env('DB_REPORTING_USERNAME'),
        'password' => env('DB_REPORTING_PASSWORD'),
        // How far behind the primary the replica may be and still answer a
        // report, in seconds. Further behind, or unreachable, and reports read
        // the primary (ReplicaLag).
        'max_lag_seconds' => env('DB_REPORTING_MAX_LAG', 30),
    ],
];
