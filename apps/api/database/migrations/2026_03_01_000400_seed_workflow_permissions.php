<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 permission catalogue additions.
 *
 * `approval.decide` and `approval.decide_any` are separate for the same reason
 * `work_item.transition` and `work_item.transition_any` are: deciding on work
 * you were asked to review and deciding on anything in the organization are
 * different trust levels (docs/06 §2).
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
            'approval.request' => 'Submit work for review',
            'approval.decide' => 'Decide on approvals they were asked to review',
            'approval.decide_any' => 'Decide on any approval in the organization',
            'approval.withdraw' => 'Withdraw an approval they requested',

            'workflow.view' => 'View workflow definitions',
            'workflow.manage' => 'Edit workflows, states, transitions, and rules',
            'workflow.run_rule' => 'Manually trigger a workflow rule',

            'notification.manage_own' => 'Change their own notification preferences',
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
