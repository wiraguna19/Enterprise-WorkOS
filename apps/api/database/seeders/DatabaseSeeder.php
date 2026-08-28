<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds from SQL rather than from factories.
 *
 * Two reasons: the fixture must be byte-identical across every environment and
 * every test run (factories with random data hide bugs that only appear on
 * certain values), and the same file can be executed by the schema verification
 * harness without booting the framework.
 *
 * Factories still exist for tests that need many rows of arbitrary data; this
 * is the fixed demo organization.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Demo seed refused: this creates known-password accounts.');

            return;
        }

        // Order matters and is not alphabetical: work items reference projects,
        // and the workflow seed references work items. Listing the files
        // explicitly rather than globbing the directory keeps that dependency
        // visible instead of leaving it to filename luck.
        $files = [
            'demo_organization.sql',   // organizations, people, roles, departments, teams
            'demo_work.sql',           // workflows, states, projects
            'demo_work_items.sql',     // work items, assignments, comments
            'demo_workflow.sql',       // transitions, rules, approvals, notifications
        ];

        foreach ($files as $file) {
            $sql = file_get_contents(__DIR__.'/sql/'.$file);

            if ($sql === false) {
                throw new \RuntimeException("Demo seed file is missing: {$file}");
            }

            DB::unprepared($sql);

            $this->command->info("Seeded {$file}.");
        }

        $this->command->info('Seeded Acme Corporation (9 people) and Globex Inc (1 person).');
        $this->command->info('All demo accounts use the password: password');
    }
}
