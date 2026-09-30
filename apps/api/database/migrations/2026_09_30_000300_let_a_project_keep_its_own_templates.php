<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A template can belong to one project (ADR 0058).
 *
 * `project_id` null is the organization-wide template it always was. Set, the
 * template is that project's: offered on its forms, written by its owner and
 * managers, and gone with the project.
 *
 * Names are unique per SCOPE: two projects may each keep a "Bug report", and a
 * project may not have two. The expression index treats every organization-
 * wide template as one scope, which a plain UNIQUE over a nullable column would
 * not — NULLs are distinct to it, and two org-wide "Bug report"s would slip
 * through.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_item_templates
                ADD COLUMN IF NOT EXISTS project_id uuid NULL;

            ALTER TABLE work_item_templates DROP CONSTRAINT IF EXISTS fk_wit_project;
            ALTER TABLE work_item_templates ADD CONSTRAINT fk_wit_project
                FOREIGN KEY (organization_id, project_id)
                REFERENCES projects (organization_id, id) ON DELETE CASCADE;

            DROP INDEX IF EXISTS uq_wit_name;
            CREATE UNIQUE INDEX uq_wit_name ON work_item_templates (
                organization_id,
                COALESCE(project_id, '00000000-0000-0000-0000-000000000000'::uuid),
                lower(name)
            );

            CREATE INDEX IF NOT EXISTS idx_wit_project
                ON work_item_templates (project_id) WHERE project_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_wit_project;
            DROP INDEX IF EXISTS uq_wit_name;
            DELETE FROM work_item_templates WHERE project_id IS NOT NULL;
            ALTER TABLE work_item_templates DROP CONSTRAINT IF EXISTS fk_wit_project;
            ALTER TABLE work_item_templates DROP COLUMN IF EXISTS project_id;
            CREATE UNIQUE INDEX uq_wit_name ON work_item_templates (organization_id, lower(name));
        SQL);
    }
};
