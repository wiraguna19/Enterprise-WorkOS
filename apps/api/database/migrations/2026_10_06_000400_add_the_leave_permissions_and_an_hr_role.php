<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Who may ask for leave, who runs it, and an HR role to hold the second
 * (ADR 0063).
 *
 * `leave.request` — ask for time off and see your own. Everyone who works
 * here: employees, managers, administrators, HR. Approving a report's request
 * needs nothing more: being their manager is the authority (ReportingLine).
 *
 * `leave.manage` — the organization's leave rules, its holidays, every
 * request, and correcting any of them. Administrators and HR.
 *
 * The `hr` role is a system role like the other four: a set of permissions,
 * never a name the code branches on (docs/06 §2). People and leave, the
 * structure they sit in, announcements — not projects or work.
 *
 * Roles are rows per organization, so the role is created here for every
 * organization that exists and repeated in the demo seed for the ones that
 * do not yet (`EveryMigrationGrantSurvivesAFreshBuildTest`).
 */
return new class extends Migration
{
    /** What HR holds. */
    public const HR = [
        'organization.view', 'department.view', 'team.view',
        'person.view', 'person.view_workload', 'person.invite', 'person.update',
        'activity.view', 'announcement.publish', 'kpi.view',
        'leave.request', 'leave.manage',
    ];

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

        $this->grant('org_admin', ['leave.request', 'leave.manage']);
        $this->grant('manager', ['leave.request']);
        $this->grant('employee', ['leave.request']);

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $exists = DB::table('roles')
                ->where('organization_id', $organizationId)
                ->where('key', 'hr')
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'id' => (string) new UuidV7,
                    'organization_id' => $organizationId,
                    'key' => 'hr',
                    'name' => 'HR',
                    'description' => 'People, leave and the structure they sit in',
                    'is_system' => true,
                    'level' => 50,
                ]);
            }
        }

        $this->grant('hr', self::HR);
    }

    public function down(): void
    {
        DB::table('roles')->where('key', 'hr')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('membership_roles')->whereColumn('membership_roles.role_id', 'roles.id'))
            ->delete();

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
            'leave.request' => 'Ask for time off and see your own requests and balance',
            'leave.manage' => 'Set the leave rules and holidays, and see and correct every request',
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
