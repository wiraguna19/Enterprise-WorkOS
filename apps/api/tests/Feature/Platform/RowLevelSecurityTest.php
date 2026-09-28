<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security, the second tenant boundary (docs/12 §4, ADR 0051).
 *
 * Every other isolation test asks whether the APPLICATION keeps tenants apart.
 * These ask the database — with queries that deliberately forget: `DB::table()`
 * has no tenant scope at all, so if one of them cannot see Globex while Acme is
 * bound, the refusal came from Postgres and from nothing else.
 *
 * Most tests switch RLS on for themselves. The first one does not: it holds
 * every tenant table to HAVING a policy, whether or not anybody has switched
 * it on, because a table without one is a hole the day somebody does.
 */
const RLS_ACME = '01900000-0000-7000-8000-0000000000ac';
const RLS_GLOBEX = '01900000-0000-7000-8000-0000000000b0';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-Level Security is a PostgreSQL feature.');
    }
});

/** Switch RLS on and bind a tenant the way a request or a job does. */
function withRls(string $organizationId, callable $callback): mixed
{
    config(['tenancy.row_level_security' => true]);

    return app(TenantContext::class)->runFor($organizationId, $callback);
}

it('has a policy on every tenant table, including ones added after it was written', function (): void {
    // The same catalogue question the migration asks — not a list, because a
    // list is what goes stale (ADR 0046).
    $unprotected = collect(DB::select(<<<'SQL'
        SELECT c.relname
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
          JOIN pg_attribute a ON a.attrelid = c.oid
                             AND a.attname = 'organization_id'
                             AND a.attnotnull
                             AND NOT a.attisdropped
         WHERE n.nspname = 'public'
           AND c.relkind IN ('r', 'p')
           AND NOT c.relispartition
           AND (
                NOT c.relrowsecurity
                OR NOT EXISTS (
                    SELECT 1 FROM pg_policies p
                     WHERE p.schemaname = 'public'
                       AND p.tablename = c.relname
                       AND p.policyname = 'tenant_isolation'
                )
           )
    SQL))->pluck('relname')->all();

    expect($unprotected)->toBe([], 'A tenant table has no Row-Level Security policy. A migration that creates a table with a NOT NULL organization_id must enable RLS on it and create the tenant_isolation policy — see 2026_09_16_000100.');
});

it('hides another organization\'s rows from a query that forgot to ask', function (): void {
    // Globex has work items; the seed says so.
    expect(DB::table('work_items')->where('organization_id', RLS_GLOBEX)->exists())->toBeTrue();

    $seen = withRls(RLS_ACME, fn () => DB::table('work_items')
        ->distinct()
        ->pluck('organization_id')
        ->all());

    // No WHERE, no scope — and still only Acme.
    expect($seen)->toBe([RLS_ACME]);
});

it('refuses to write a row into another organization', function (): void {
    // Inside a savepoint, so the refused INSERT does not poison the test's
    // transaction — and the policy's WITH CHECK is what refuses it, not a
    // missing column: the row is the isolation suite's own minimal Globex row.
    expect(fn () => withRls(RLS_ACME, fn () => DB::transaction(
        fn () => DB::table('work_item_templates')->insert(minimalRowFor('work_item_templates', RLS_GLOBEX)),
    )))->toThrow(QueryException::class, 'row-level security');
});

it('refuses to update another organization\'s rows, silently, as if they were not there', function (): void {
    $touched = withRls(RLS_ACME, fn (): int => DB::table('work_items')
        ->where('organization_id', RLS_GLOBEX)
        ->update(['title' => 'Rewritten from Acme']));

    expect($touched)->toBe(0)
        ->and(DB::table('work_items')->where('title', 'Rewritten from Acme')->exists())->toBeFalse();
});

it('steps aside for platform mode, which is how a deliberate crossing says so', function (): void {
    $organizations = withRls(RLS_ACME, fn () => app(TenantContext::class)->runAsPlatform(
        'test: read across tenants',
        fn () => DB::table('work_items')->distinct()->pluck('organization_id')->all(),
    ));

    expect($organizations)->toContain(RLS_ACME)->toContain(RLS_GLOBEX);
});

it('lets go when the tenant is released, so the next job does not inherit it', function (): void {
    withRls(RLS_ACME, fn () => DB::table('work_items')->count());

    // runFor has returned: no tenant is bound, and the role is reset with it.
    $organizations = DB::table('work_items')->distinct()->pluck('organization_id')->all();

    expect($organizations)->toContain(RLS_GLOBEX)
        ->and(DB::selectOne('SELECT current_user AS who')?->who)->not->toBe('workos_tenant');
});

it('is off unless switched on — the same query sees every tenant', function (): void {
    config(['tenancy.row_level_security' => false]);

    $organizations = app(TenantContext::class)->runFor(
        RLS_ACME,
        fn () => DB::table('work_items')->distinct()->pluck('organization_id')->all(),
    );

    expect($organizations)->toContain(RLS_GLOBEX);
});

it('carries a whole request through with it on — sign-in, a list, a write, a switch', function (): void {
    config(['tenancy.row_level_security' => true]);

    $rina = $this->loginAs('rina@acme.test');

    $this->withToken($rina)->getJson('/api/v1/work-items?limit=5')->assertOk();

    $this->withToken($rina)
        ->postJson('/api/v1/work-items', ['title' => 'Written under Row-Level Security'])
        ->assertStatus(201);

    // The switcher reads across tenants on purpose, through platform mode;
    // without it, the database would show Rina one membership and nowhere to go.
    $ids = collect($this->withToken($rina)->getJson('/api/v1/auth/organizations')->assertOk()->json('data'))
        ->pluck('id')
        ->all();

    expect($ids)->toContain(RLS_GLOBEX);

    $globex = (string) $this->withToken($rina)
        ->postJson('/api/v1/auth/organization', ['organization_id' => RLS_GLOBEX])
        ->assertOk()
        ->json('data.token');

    $this->withToken($globex)->getJson('/api/v1/work-items/ENG-142')->assertNotFound();
});
