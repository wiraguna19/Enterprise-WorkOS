<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One permission for custom fields, not three.
 *
 * `custom_field.manage` governs declaring, editing, reordering, retiring and
 * deleting a field DEFINITION — an organization-shaping act, held by the org
 * admin alone.
 *
 * There is deliberately no `custom_field.view` and no `custom_field.edit`:
 *
 * - **Reading a field's value is reading the item it is on.** A second
 *   permission in front of it would be a third answer to a question two layers
 *   already answer, and docs/06 §2's recurring failure is exactly that — two
 *   layers answering the same question differently, with the coarse one
 *   silently winning.
 * - **Writing a value is editing the item.** Somebody who may change a work
 *   item's title may fill in its client field; somebody who may not, may not.
 *   Splitting them would make "edit this item, except these parts" a state the
 *   UI has no way to show and nobody asked for.
 *
 * Seeded now rather than in Phase 2 for the reason every permission migration
 * in this project gives: a permission that exists before the feature enforcing
 * it lets a role grant an ability nothing checks, which reads as protection and
 * is not. `activity.view` sat granted to every role with nothing behind it for
 * five phases.
 *
 * Applied to EXISTING roles too — a seeder only helps a fresh database.
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

        $this->grant('org_admin', ['custom_field.manage']);
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
            'custom_field.manage' => 'Declare, edit and retire custom fields for the organization',
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
