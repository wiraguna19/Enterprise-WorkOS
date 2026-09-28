<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Middleware\ReadFromReportingReplica;
use App\Modules\Platform\Infrastructure\Database\ReportingReplica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports read from a replica, when there is one (ADR 0053).
 *
 * There is no replica in the test database, so these tests make one out of the
 * primary: a `reporting` connection that SHARES the primary's session. It has
 * to — RefreshDatabase keeps every row a test arranges inside an open
 * transaction, and a second session would not see any of them. What is being
 * tested is the routing, and the routing is visible in which connection's
 * query log a query lands in.
 */
const REPLICA_ACME = '01900000-0000-7000-8000-0000000000ac';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The replica routing is written for PostgreSQL.');
    }

    config(['database.connections.reporting' => null]);
});

function replicaSharingThePrimary(): void
{
    $primary = DB::getDefaultConnection();

    config(['database.connections.reporting' => config("database.connections.{$primary}")]);
    DB::purge('reporting');

    $pdo = DB::connection($primary)->getPdo();
    DB::connection('reporting')->setPdo($pdo)->setReadPdo($pdo);
    DB::connection('reporting')->enableQueryLog();
}

/** @return list<string> */
function replicaQueries(): array
{
    return array_values(array_map(
        fn (array $entry): string => (string) $entry['query'],
        DB::connection('reporting')->getQueryLog(),
    ));
}

/** Which connection the default was, inside the middleware, for this method. */
function defaultInsideMiddleware(string $method): string
{
    $seen = '';

    app(ReadFromReportingReplica::class)->handle(
        Request::create('/api/v1/insights/flow', $method),
        function () use (&$seen): Response {
            $seen = DB::getDefaultConnection();

            return response('ok');
        },
    );

    return $seen;
}

it('changes nothing when no replica is configured', function (): void {
    expect(app(ReportingReplica::class)->isConfigured())->toBeFalse()
        ->and(defaultInsideMiddleware('GET'))->toBe(DB::getDefaultConnection());
});

it('builds the replica connection from the primary, with only the host and credentials overridden', function (): void {
    config([
        'database.reporting.host' => 'replica.internal',
        'database.reporting.port' => null,
    ]);

    ReportingReplica::defineConnection();

    $primary = config('database.connections.'.config('database.default'));
    $replica = config('database.connections.reporting');

    expect($replica['host'])->toBe('replica.internal')
        ->and($replica['port'])->toBe($primary['port'])
        ->and($replica['database'])->toBe($primary['database'])
        ->and($replica['application_name'])->toBe('workos-reporting');
});

it('defines nothing without a replica host', function (): void {
    config(['database.reporting.host' => null]);

    ReportingReplica::defineConnection();

    expect(config('database.connections.reporting'))->toBeNull();
});

it('serves an insight from the replica, and hands the default back afterwards', function (): void {
    replicaSharingThePrimary();
    $primary = DB::getDefaultConnection();
    $token = $this->loginAs('rina@acme.test');

    $this->withToken($token)->getJson('/api/v1/insights/flow')->assertOk();

    expect(collect(replicaQueries())->contains(fn (string $sql): bool => str_contains($sql, 'work_item')))->toBeTrue()
        ->and(DB::getDefaultConnection())->toBe($primary);
});

it('never sends a write to the replica', function (): void {
    replicaSharingThePrimary();

    // Only safe methods are routed: a POST under the middleware reaches the
    // primary, because a replica would refuse the write it is about to make.
    expect(defaultInsideMiddleware('POST'))->toBe(DB::getDefaultConnection())
        ->and(defaultInsideMiddleware('GET'))->toBe('reporting');
});

it('keeps the export rows on the primary', function (): void {
    replicaSharingThePrimary();
    $token = $this->loginAs('rina@acme.test');

    // A lagging copy must not say "no such export" about a file just asked for.
    $this->withToken($token)->getJson('/api/v1/reports/exports')->assertOk();

    expect(collect(replicaQueries())->contains(fn (string $sql): bool => str_contains($sql, 'report_exports')))->toBeFalse();
});

it('tells the replica which organization is in force, when Row-Level Security is on', function (): void {
    replicaSharingThePrimary();
    config(['tenancy.row_level_security' => true]);

    actingWithinTenant(REPLICA_ACME, fn () => app(ReportingReplica::class)->run(
        fn () => DB::table('work_items')->count(),
    ));

    // The boundary lives in a database session, so the replica's session is
    // told too — the organization first, then the role (ADR 0051).
    expect(collect(replicaQueries())->contains(fn (string $sql): bool => str_contains($sql, 'SET ROLE workos_tenant')))->toBeTrue();
});
