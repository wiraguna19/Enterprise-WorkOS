<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/**
 * A grant a migration makes must also survive building the database from
 * nothing.
 *
 * Migrations run BEFORE the seed, and the system roles are created BY the seed.
 * So a migration that says `grant('manager', ['report.view'])` matches no role
 * at all on a fresh database and silently does nothing — while working
 * perfectly on the developer's own machine, where the roles were already there.
 *
 * That is not hypothetical. `report.view` was granted to `manager` by a Phase 6
 * migration whose docblock even explains that it covers existing roles; the
 * seed was never updated to match. `org_admin` holds every permission by CROSS
 * JOIN, so the flow report worked for the one person it was tested as, and
 * every manager — the role docs/06 names as its audience — saw no Flow entry
 * in the nav and a 403 if they typed the URL. Two phases, invisible.
 *
 * The check is a grep, like the other guards here: it reads the migrations for
 * grant calls and asks the seeded database whether those grants are real.
 */
it('grants every migration-granted permission on a freshly seeded database', function (): void {
    $found = [];

    foreach (Finder::create()->files()->in(database_path('migrations'))->name('*.php') as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        // $this->grant('manager', ['report.view', 'report.export']);
        preg_match_all(
            "/grant\(\s*'([a-z_]+)'\s*,\s*\[([^\]]*)\]/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $role, $keys]) {
            preg_match_all("/'([a-z_.]+)'/", $keys, $permissions);

            foreach ($permissions[1] as $permission) {
                $found[] = [$role, $permission, $file->getFilename()];
            }
        }
    }

    expect($found)->not->toBeEmpty();

    foreach ($found as [$role, $permission, $migration]) {
        $granted = DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.key', $role)
            ->where('p.key', $permission)
            ->exists();

        $this->assertTrue($granted, <<<WHY
            {$migration} grants `{$permission}` to `{$role}`, and a freshly seeded
            database does not have it. Migrations run before the seed creates the
            system roles, so that grant matched nothing here. Repeat it in
            database/seeders/sql/demo_organization.sql — the migration is for
            databases that already exist, the seed is for the ones that do not.
            WHY);
    }
});
