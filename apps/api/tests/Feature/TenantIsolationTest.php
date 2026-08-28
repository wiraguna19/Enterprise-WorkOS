<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Eloquent\RoleModel;
use App\Modules\Identity\Infrastructure\Eloquent\SessionModel;
use App\Modules\Platform\Domain\Exception\TenantContextMissing;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use App\Modules\Platform\Infrastructure\Eloquent\Concerns\BelongsToOrganization;
use App\Modules\Platform\Infrastructure\Eloquent\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * The single most important test file in the project.
 *
 * It does not test a list of models someone remembered to add. It DISCOVERS
 * every model by reflection and asserts the isolation guarantees against each
 * one, so a new model that forgets the trait fails CI instead of leaking in
 * production (docs/11 §3).
 *
 * Every assertion here corresponds to a documented guarantee in docs/01 §6.
 */

/**
 * The application directory, resolved from this file rather than from
 * app_path().
 *
 * Pest resolves a dataset closure BEFORE the application is booted, so `app()`
 * there returns a bare container and `app_path()` dies with "Call to undefined
 * method Container::path()". Anything a dataset touches must therefore be
 * framework-free.
 */
function appDirectory(): string
{
    return dirname(__DIR__, 2).'/app';
}

/**
 * @return list<class-string<Model>>
 */
function allModelClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(appDirectory().'/Modules')->name('*Model.php') as $file) {
        $relative = str_replace(
            ['/', '.php'],
            ['\\', ''],
            substr($file->getRealPath(), strlen(appDirectory()) + 1),
        );

        $class = 'App\\'.$relative;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var class-string<Model> $class */
        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * Models that carry organization_id and are nonetheless NOT tenant-scoped.
 *
 * There is exactly one, and it cannot be otherwise: a session is resolved to
 * authenticate the request, which is strictly BEFORE any tenant context exists.
 * Scoping it would make the scope depend on the very lookup that establishes
 * it, and no one could ever log in. Its own organization_id is what the tenant
 * context is then built FROM (docs/06 §1).
 *
 * @var list<class-string<Model>>
 */
const NOT_TENANT_SCOPED = [
    SessionModel::class,
];

/** Models whose table carries organization_id — those must be tenant-scoped. */
function tenantScopedModels(): array
{
    return array_values(array_filter(
        allModelClasses(),
        fn (string $class) => isTenantScoped($class),
    ));
}

/**
 * Requires a booted application (Schema hits the connection), so it may only be
 * called from inside a test body — never from a dataset closure.
 */
function isTenantScoped(string $class): bool
{
    if (in_array($class, NOT_TENANT_SCOPED, strict: true)) {
        return false;
    }

    return Schema::hasColumn((new $class)->getTable(), 'organization_id');
}

it('discovers models to check', function (): void {
    // A guard against the suite silently passing because discovery broke.
    expect(allModelClasses())->not->toBeEmpty()
        ->and(tenantScopedModels())->not->toBeEmpty();
});

it('scopes every model whose table has organization_id', function (): void {
    $unscoped = [];

    foreach (tenantScopedModels() as $class) {
        $usesTrait = in_array(
            BelongsToOrganization::class,
            class_uses_recursive($class),
            strict: true,
        );

        if (! $usesTrait) {
            $unscoped[] = $class;
        }
    }

    expect($unscoped)->toBe([], sprintf(
        "These models have an organization_id column but are not tenant-scoped.\n".
        "Extend %s instead of BaseModel:\n  - %s",
        TenantModel::class,
        implode("\n  - ", $unscoped),
    ));
});

it('refuses to query a tenant-scoped model without tenant context', function (string $class): void {
    if (! isTenantScoped($class)) {
        $this->markTestSkipped($class.' is not tenant-scoped.');
    }

    app(TenantContext::class)->reset();

    expect(fn () => $class::query()->first())
        ->toThrow(TenantContextMissing::class);
})->with(fn () => allModelClasses());

it('hides another organization\'s records', function (string $class): void {
    if (! isTenantScoped($class)) {
        $this->markTestSkipped($class.' is not tenant-scoped.');
    }

    [$acme, $globex] = seedTwoTenants();

    $table = (new $class)->getTable();

    actingWithinTenant($globex, function () use ($table, $globex): void {
        DB::table($table)->insert(minimalRowFor($table, $globex));
    });

    actingWithinTenant($acme, function () use ($class, $globex): void {
        $visible = $class::query()->get();

        expect($visible->pluck('organization_id')->unique()->all())
            ->not->toContain($globex, 'A record from another organization was visible.');
    });
})->with(fn () => allModelClasses());

it('fills organization_id from context and ignores client input', function (): void {
    [$acme, $globex] = seedTwoTenants();

    actingWithinTenant($acme, function () use ($acme, $globex): void {
        $role = new RoleModel;

        // A malicious or careless caller supplies someone else's tenant.
        $role->forceFill([
            'id' => RoleModel::newId(),
            'organization_id' => $globex,
            'key' => 'injected',
            'name' => 'Injected',
        ])->save();

        expect($role->fresh()->organization_id)->toBe($acme);
    });
});

it('returns 404, not 403, for a resource in another tenant', function (): void {
    // A 403 confirms the record exists, which is itself a cross-tenant
    // information leak (docs/05 §3).
    [$acme, $globex] = seedTwoTenants();

    $foreignTeam = createTeamIn($globex);

    $response = $this->actingAsMemberOf($acme)->getJson("/api/v1/teams/{$foreignTeam->id}");

    $response->assertNotFound();
    expect($response->json('error.code'))->toBe('resource.not_found');
});

it('only enters platform mode through the audited escape hatch', function (): void {
    // There must be exactly one way to cross tenants, and it must be greppable.
    $offenders = [];

    foreach (Finder::create()->files()->in(appDirectory().'/Modules')->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        // The CALL, not the word: this file's own prose, and the comments that
        // explain why a bypass is absent, must not read as violations.
        if (! str_contains($contents, '->withoutGlobalScope')) {
            continue;
        }

        // The three legitimate sites: the trait itself, the tenant resolver
        // (pre-tenant by definition), and login (which CHOOSES the tenant).
        $allowed = str_contains($file->getRealPath(), 'ResolveTenant.php')
            || str_contains($file->getRealPath(), 'AuthenticationService.php')
            || str_contains($file->getRealPath(), 'BelongsToOrganization.php');

        if (! $allowed) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], sprintf(
        "withoutGlobalScope() bypasses tenant isolation. Use TenantContext::runAsPlatform()\n".
        "so the crossing is logged and reviewable:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
