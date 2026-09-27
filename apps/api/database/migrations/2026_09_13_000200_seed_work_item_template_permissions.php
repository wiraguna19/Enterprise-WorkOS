<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One permission for templates, and none for reading them (ADR 0047).
 *
 * `work_item_template.manage` governs writing, changing and deleting a template
 * — organization configuration, held by the org admin, as `custom_field.manage`
 * is.
 *
 * There is deliberately no `work_item_template.view`. The person who READS a
 * template is the person filling in the create form, so the read is guarded by
 * `work_item.create`: a separate view permission would be one more thing an
 * administrator has to remember to grant before the picker stops being empty,
 * and an empty picker reads as "this organization has no templates", which is
 * a confident lie. ADR 0038 learned the same lesson the expensive way — a
 * create form that read the administration endpoint made one required field a
 * lockout for everybody but an admin.
 *
 * Seeded with the feature rather than ahead of it: a permission that exists
 * before anything enforces it lets a role grant an ability nothing checks.
 *
 * Applied to EXISTING roles too — a seeder only helps a fresh database. On a
 * fresh one the seed's org_admin CROSS JOIN picks it up, because migrations
 * run before the seed; `EveryMigrationGrantSurvivesAFreshBuildTest` asks.
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

        $this->grant('org_admin', ['work_item_template.manage']);
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
            'work_item_template.manage' => 'Write, change and delete the work item templates of the organization',
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
