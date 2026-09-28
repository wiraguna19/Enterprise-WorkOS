<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One permission for single sign-on (ADR 0052).
 *
 * `sso.manage` decides WHO may vouch for the people in this organization:
 * which identity provider, with which certificate, for which domains — and
 * whether passwords still work here at all. Held by the org admin.
 *
 * Deliberately not `organization.manage_settings`. That key shortens a session
 * lifetime; this one names a third party whose word signs anybody in. An
 * organization that wants somebody to tune the idle timeout without being able
 * to point sign-in at their own IdP needs the two apart.
 *
 * It is also the break-glass: holding it lets a person sign in with a password
 * while SSO is enforced, so a broken IdP never locks out the people who can fix
 * the connection.
 *
 * Applied to existing roles too; on a fresh database the seed's org_admin
 * CROSS JOIN picks it up, and `EveryMigrationGrantSurvivesAFreshBuildTest`
 * asks.
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

        $this->grant('org_admin', ['sso.manage']);
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
            'sso.manage' => 'Configure the identity provider people sign in through, and whether passwords still work',
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
