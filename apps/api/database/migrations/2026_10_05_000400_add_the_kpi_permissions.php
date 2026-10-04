<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and keep key performance indicators (ADR 0062).
 *
 * `kpi.view` — the KPIs of teams, departments and projects. Employees,
 * managers and admins. A project's KPIs are further limited to people who can
 * see the project.
 *
 * `kpi.manage` — create, change and archive them, and record their values.
 * Managers and admins, and grantable on one team, department or project.
 *
 * A KPI about ONE PERSON is governed by neither: it is visible only along that
 * person's reporting line (ADR 0062, "Per person").
 *
 * Repeated for `manager` and `employee` in the demo seed, because migrations
 * run before the seed creates the roles (`EveryMigrationGrantSurvivesAFreshBuildTest`).
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

        $this->grant('org_admin', ['kpi.view', 'kpi.manage']);
        $this->grant('manager', ['kpi.view', 'kpi.manage']);
        $this->grant('employee', ['kpi.view']);
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
            'kpi.view' => 'See the KPIs of teams, departments and projects',
            'kpi.manage' => 'Create, change and archive KPIs of teams, departments and projects, and record their values',
        ];
    }

    /**
     * A UUIDv5 of the permission key, so the same permission has the same id in
     * every environment.
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
