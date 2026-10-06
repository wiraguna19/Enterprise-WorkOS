<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Finished work for each demo person in the last twelve weeks, so the
 * Delivery panel shows every state it can be in (ADR 0062).
 *
 *   php artisan db:seed --class=DemoDeliverySeeder
 *
 * Not part of DatabaseSeeder: it adds a project (SUP), and the tests count
 * and reference the others. Run it again at any time to bring the history up
 * to today: weeks already filled are skipped, the weeks since are added, and
 * nothing is deleted (transitions are append-only).
 */
final class DemoDeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Demo seed refused in production.');

            return;
        }

        $sql = file_get_contents(__DIR__.'/sql/demo_delivery.sql');

        if ($sql === false) {
            throw new \RuntimeException('Demo seed file is missing: demo_delivery.sql');
        }

        DB::unprepared($sql);

        $count = DB::table('work_items')->where('project_id', '01900003-0000-7000-8000-0000000000d1')->count();

        $this->command->info("Seeded delivery history: {$count} finished items in Support Desk (SUP).");
    }
}
