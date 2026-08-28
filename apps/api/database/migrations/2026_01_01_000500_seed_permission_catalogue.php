<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue ships in a migration, not a seeder.
 *
 * Seeders are optional and environment-specific; authorization vocabulary is
 * not. If a permission key exists on staging but not in production, the same
 * role means two different things — so this is schema, in effect (docs/03 §8).
 *
 * Adding a permission later is a new migration. Keys are never renamed in place;
 * a rename is an add + a backfill + a remove, so no deployment window exists in
 * which a role silently loses an ability.
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
                // Deterministic IDs: re-running this migration on a fresh
                // database yields the same permission IDs everywhere, which
                // makes role fixtures and support queries portable.
                'id' => $this->deterministicUuid($key),
                'key' => $key,
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('permissions')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', array_keys($this->catalogue()))->delete();
    }

    /**
     * Phase 2 catalogue. Work, workflow, and approval permissions are added by
     * their own phases' migrations — declaring them before the features exist
     * would let a role grant an ability nothing enforces.
     *
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        return [
            // Organization
            'organization.view' => 'View organization profile and settings',
            'organization.update' => 'Change organization profile',
            'organization.manage_settings' => 'Change organization-wide settings',

            // Structure
            'department.view' => 'View departments',
            'department.create' => 'Create departments',
            'department.update' => 'Rename or move departments',
            'department.delete' => 'Archive departments',
            'team.view' => 'View teams',
            'team.create' => 'Create teams',
            'team.update' => 'Update teams',
            'team.delete' => 'Archive teams',
            'team.manage_members' => 'Add or remove team members',

            // People
            'person.view' => 'View the people directory',
            'person.invite' => 'Invite people to the organization',
            'person.update' => 'Update employee profiles',
            'person.deactivate' => 'Revoke a person\'s access',
            'person.view_workload' => 'See workload for other people',

            // Access control
            'role.view' => 'View roles and permissions',
            'role.manage' => 'Create, edit, and assign roles',

            // Governance
            'audit_log.view' => 'Read the security audit log',
            'activity.view' => 'Read activity timelines',
        ];
    }

    /**
     * UUID v5 in the DNS namespace, derived from the key.
     */
    private function deterministicUuid(string $key): string
    {
        $namespace = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $hash = sha1(hex2bin(str_replace('-', '', $namespace)).$key, true);

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
