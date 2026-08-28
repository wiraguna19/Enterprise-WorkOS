<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 permission catalogue additions.
 *
 * Declared here rather than in Phase 2 on purpose: a permission that exists
 * before the feature that enforces it lets a role grant an ability nothing
 * checks — which is worse than no permission at all, because it reads as
 * protection (docs/10, sequencing rationale).
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

        DB::table('permissions')->insert($rows);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', array_keys($this->catalogue()))->delete();
    }

    /** @return array<string, string> */
    private function catalogue(): array
    {
        return [
            'project.view' => 'View projects they are a member of',
            'project.view_all' => 'View every project in the organization',
            'project.create' => 'Create projects',
            'project.update' => 'Update project details',
            'project.delete' => 'Delete projects',
            'project.archive' => 'Archive projects',
            'project.manage_members' => 'Add or remove project members',

            'milestone.manage' => 'Create and update milestones',

            'work_item.view' => 'View work items',
            'work_item.create' => 'Create work items',
            'work_item.update' => 'Update work items',
            'work_item.delete' => 'Delete work items',
            'work_item.assign' => 'Assign work to other people',
            'work_item.transition' => 'Move work through its workflow',
            'work_item.submit' => 'Submit work for review',
            // The override. Separate from work_item.transition because
            // "can move work forward" and "can ignore the rules that guard it"
            // are different trust levels (docs/02 §4.2).
            'work_item.transition_any' => 'Override transition guards, with a recorded reason',
            'work_item.log_time' => 'Log time against work items',

            'comment.create' => 'Comment on work and projects',
            'comment.update_own' => 'Edit their own comments',
            'comment.delete_any' => 'Delete anyone\'s comments',

            'file.upload' => 'Attach files',
            'file.delete_any' => 'Remove anyone\'s attachments',

            'tag.manage' => 'Create and edit tags',
            'saved_view.share' => 'Share saved views with a team or the organization',
        ];
    }

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
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12),
        );
    }
};
