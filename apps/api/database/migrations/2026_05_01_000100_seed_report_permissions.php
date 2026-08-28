<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 permission catalogue additions.
 *
 * `docs/06` §2 lists `report.view` and `report.export` in the catalogue, and
 * neither was ever seeded — so the roles that the same document says hold them
 * did not, and any route guarded on them would have refused everyone. The same
 * shape as the policies that were written but never registered: declared in one
 * place, absent from the place that decides.
 *
 * Added now rather than in Phase 2 for the reason the Phase 3 migration gives:
 * a permission that exists before the feature enforcing it lets a role grant an
 * ability nothing checks, which reads as protection and is not.
 *
 * The grants are applied to EXISTING roles too. A seeder only helps a fresh
 * database; an organization created last month has an org_admin role that would
 * otherwise silently lack a permission its own definition claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($this->catalogue() as $key => $description) {
            [$resource, $action] = explode('.', $key, 2);

            $rows[] = [
                'id' => $this->deterministicUuid($key),
                'key' => $key,
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
            ];
        }

        DB::table('permissions')->insertOrIgnore($rows);

        // Org Admin holds everything within its organization; Manager gets
        // reports per docs/06's role table ("...approve, team and workload
        // visibility, reports"). Export stays with the admin: a full extract of
        // delivery data is an organization-level act.
        $this->grant('org_admin', ['report.view', 'report.export']);
        $this->grant('manager', ['report.view']);
    }

    public function down(): void
    {
        $keys = array_keys($this->catalogue());

        DB::table('role_permissions')->whereIn(
            'permission_id',
            DB::table('permissions')->whereIn('key', $keys)->pluck('id')
        )->delete();

        DB::table('permissions')->whereIn('key', $keys)->delete();
    }

    /** @param list<string> $keys */
    private function grant(string $roleKey, array $keys): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.key = ?
               AND p.key = ANY (?)
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions x
                    WHERE x.role_id = r.id AND x.permission_id = p.id
               )
        SQL, [$roleKey, '{'.implode(',', $keys).'}']);
    }

    /** @return array<string, string> */
    private function catalogue(): array
    {
        return [
            'report.view' => 'View delivery reports and organization metrics',
            'report.export' => 'Export report data',
        ];
    }

    /**
     * A UUIDv5 of the permission key, so the same permission has the same id in
     * every environment — which is what lets a role grant be written against a
     * key rather than against whatever id a particular database happened to
     * generate.
     */
    private function deterministicUuid(string $key): string
    {
        $namespace = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $hash = sha1((string) hex2bin(str_replace('-', '', $namespace)).$key, true);

        $bytes = substr($hash, 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
};
