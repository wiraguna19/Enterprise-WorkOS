<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Database;

use App\Modules\Platform\Domain\Contract\TenantBoundary;
use Illuminate\Support\Facades\DB;

/**
 * The database's own answer to "whose rows are these" (ADR 0051).
 *
 * When a tenant is bound, the connection becomes `workos_tenant` and carries
 * the organization in `app.organization_id`; every table with a NOT NULL
 * `organization_id` has a policy for that role that shows and accepts only the
 * organization's own rows. So a query that forgets its WHERE — a raw
 * `DB::table()`, a `withoutGlobalScopes()` in a hurry — still cannot read or
 * write another tenant's data. The application scope stays; this is the
 * second lock, on the drawer rather than the door (docs/12 §4).
 *
 * When no tenant is bound — before authentication, in platform mode, in the
 * scheduler reading across tenants on purpose, in migrations — the role is
 * reset, and the policies (written `TO workos_tenant`) do not apply. That is
 * what keeps every deliberate cross-tenant path working without a list of
 * exceptions: they already had to say so, through `runAsPlatform()` or by
 * running with no tenant at all.
 *
 * Off unless `tenancy.row_level_security` is on. The policies are installed
 * either way, and a test holds every tenant table to having one; switching it
 * on is configuration, not a migration.
 *
 * Nothing is cached about what was last applied. `SET ROLE` inside a
 * transaction is undone when that transaction rolls back, so remembering the
 * last value would eventually remember something the database has forgotten.
 * Two statements per change, only when enabled, is the price of never lying.
 */
final class PostgresRowLevelSecurity implements TenantBoundary
{
    public const ROLE = 'workos_tenant';

    public function enter(?string $organizationId): void
    {
        if (! (bool) config('tenancy.row_level_security') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($organizationId === null) {
            DB::statement('RESET ROLE');
            DB::select("SELECT set_config('app.organization_id', '', false)");

            return;
        }

        // The setting first, then the role: in the other order there is a
        // moment where the role is in force with no organization, and the
        // policy answers that moment with "no rows", which is safe but would
        // make a failure here look like an empty tenant.
        DB::select("SELECT set_config('app.organization_id', ?, false)", [$organizationId]);
        DB::statement('SET ROLE '.self::ROLE);
    }
}
