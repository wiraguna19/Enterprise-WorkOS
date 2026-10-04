<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Only the demo KPIs, for a database that was seeded before they existed.
 *
 *   php artisan db:seed --class=DemoKpiSeeder
 *
 * Safe to run more than once: the file's ids are fixed and every insert skips
 * what is already there. A fresh `migrate:fresh --seed` includes it anyway,
 * through DatabaseSeeder.
 */
final class DemoKpiSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Demo seed refused in production.');

            return;
        }

        $sql = file_get_contents(__DIR__.'/sql/demo_kpis.sql');

        if ($sql === false) {
            throw new \RuntimeException('Demo seed file is missing: demo_kpis.sql');
        }

        DB::unprepared($sql);

        $this->command->info('Seeded the demo KPIs (7 for groups, 3 for people).');
    }
}
