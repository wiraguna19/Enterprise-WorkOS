<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Service accounts: members of an organization that are not people (ADR 0059).
 *
 * `users.kind` says which. A service account is a `users` row of kind
 * `service` with exactly one membership, so it inherits everything a
 * membership already has — roles, permissions, Row-Level Security, visibility,
 * the activity log's actor — instead of a parallel set of answers for a token
 * that belongs to nobody (ADR 0049's "still owed").
 *
 * `service_account.manage` goes to org admins: making something that acts in
 * the organization's name, with a role, is an administrator's act.
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

        $this->grant('org_admin', ['service_account.manage']);

        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN IF NOT EXISTS kind varchar(10) NOT NULL DEFAULT 'person';

            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_kind;
            ALTER TABLE users ADD CONSTRAINT ck_users_kind CHECK (kind IN ('person', 'service'));

            -- A service account cannot hold a credential a person would:
            -- no password to sign in with, no second factor to enrol. Its
            -- only way in is a token an administrator issued.
            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_service_has_no_credentials;
            ALTER TABLE users ADD CONSTRAINT ck_users_service_has_no_credentials CHECK (
                kind = 'person'
                OR (password_hash IS NULL AND mfa_secret_encrypted IS NULL AND is_platform_admin = false)
            );
        SQL);
    }

    public function down(): void
    {
        $keys = array_keys($this->catalogue());

        DB::table('role_permissions')->whereIn(
            'permission_id',
            DB::table('permissions')->whereIn('key', $keys)->pluck('id')
        )->delete();

        DB::table('permissions')->whereIn('key', $keys)->delete();

        DB::unprepared(<<<'SQL'
            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_service_has_no_credentials;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_kind;
            ALTER TABLE users DROP COLUMN IF EXISTS kind;
        SQL);
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
            'service_account.manage' => 'Create integrations that act without a person, give them a role, and issue and revoke their tokens',
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
