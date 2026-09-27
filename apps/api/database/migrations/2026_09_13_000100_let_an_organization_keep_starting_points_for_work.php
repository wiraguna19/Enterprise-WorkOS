<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work item templates (docs/10 Phase 7, ADR 0047).
 *
 * docs/10 lists templates TWICE — under Workflow and under Extensibility — and
 * the only template in the schema was the one embedded in `recurrences`, which
 * nobody can pick from a form. This is the other half: a named starting point
 * an organization keeps, which a person chooses when they create work.
 *
 * ## A template is a starting point, not a second way in
 *
 * Nothing here creates a work item. A template PREFILLS the create form, and
 * the person submits that form to the same `POST /work-items` everybody else
 * does — same validator, same required custom fields, same permission. A
 * "create from template" endpoint would be a second door into one behaviour,
 * and this codebase has learned what happens to the weaker of two doors
 * (`/move` could perform every transition but the ones that needed a reason).
 *
 * ## What a template may hold, and what it refuses
 *
 * `fields` is jsonb, like the recurrence template it deliberately resembles,
 * and for the same reason: half a work item's columns (state, reference,
 * position) only exist once it does. What the jsonb may contain is decided by
 * the service, which refuses anything else BY NAME:
 *
 * - **No people.** A template outlives the people it would name — they leave,
 *   they are erased — and who does a piece of work is a decision made per item,
 *   on the form, which already asks.
 * - **No absolute dates.** An absolute due date in a template is the same date
 *   forever. `due_in_days` is relative to the day the form opens — the rule the
 *   recurrence template already follows.
 * - **No project.** A template is organization configuration; the project comes
 *   from where the form was opened (`/work/new?project=KEY`). Project-scoped
 *   templates are owed, and named as owed in ADR 0047.
 *
 * ## No provenance column, on purpose
 *
 * `work_items` gains no `template_id`. A prefill the person is free to change
 * in every field does not make the item "from" the template in any sense a
 * report could rely on — and a column recording a relationship that may be
 * false is the column this project keeps finding written by one thing and
 * read by nothing.
 *
 * ## Deleted, not retired
 *
 * Custom fields are retired rather than deleted because records still carry
 * their answers. Nothing refers to a template — see above — so a retired one
 * would be a row kept for nobody. Deleting is honest here, and the audit log
 * keeps what it held.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_item_templates (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,

                -- What the picker shows. Unique per organization, compared
                -- case-insensitively: two templates called "Bug report" and
                -- "bug report" are one choice rendered twice, and the person
                -- picking cannot tell which one they meant.
                name               varchar(80)   NOT NULL,

                -- What it is FOR, in a sentence. Not the item's description —
                -- that lives inside `fields` and is what the new item says.
                purpose            varchar(500)  NULL,

                -- The prefill. Shape owned by WorkItemTemplates, which refuses
                -- unknown keys by name rather than storing them: a jsonb column
                -- with no constraint is read back forever, and a key nothing
                -- honours is indistinguishable from one that stopped working.
                fields             jsonb         NOT NULL DEFAULT '{}'::jsonb,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_wit_fields_object
                    CHECK (jsonb_typeof(fields) = 'object')
            );

            -- The name is decided HERE, not by a SELECT before the insert: two
            -- administrators saving "Bug report" in the same second both pass a
            -- read, and only the index can refuse one of them.
            CREATE UNIQUE INDEX uq_wit_name
                ON work_item_templates (organization_id, lower(name));

            CREATE UNIQUE INDEX uq_work_item_templates_org_id
                ON work_item_templates (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS work_item_templates CASCADE;');
    }
};
