<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Custom fields: the definition + value model docs/02 §9 has specified since
 * Phase 2 (ADR 0038).
 *
 * Six documents describe this feature. docs/02 §4 says a work item "contains
 * its custom field values"; docs/03 §8 names them as the example of a pure
 * child that may CASCADE; docs/05 §4 publishes the filter grammar
 * `filter[cf_client]=Acme`; docs/08 §5 puts them in the More section of the
 * work item form; docs/12 counts them among what the domain model bought.
 * **Not one line of code existed.** Grepping the whole repo for `custom_field`
 * returned nothing at all before this migration.
 *
 * That is this project's most repeated defect wearing its documentation
 * variant: a capability asserted often enough that everybody stopped checking.
 * A comment asserting a capability is not evidence of one, and neither is a
 * table in a spec.
 *
 * ## Why definition + value, and not the two obvious shortcuts
 *
 * A **JSONB column on `work_items`** cannot be validated, cannot be renamed
 * safely, and cannot be listed — and a filter UI has to be able to ask "what
 * fields exist and what may they contain" without reading every row.
 *
 * **Runtime `ALTER TABLE`** makes every tenant's schema different, which turns
 * one migration into N, and makes a query planner's statistics meaningless.
 *
 * ## Typed columns, and exactly one of them
 *
 * `value_text` / `value_number` / `value_date` rather than one stringly column,
 * because sorting and filtering on a custom field is not a future maybe — it is
 * already published grammar — and casting text to date in a WHERE clause across
 * millions of rows is not survivable.
 *
 * The CHECK that exactly one is non-null is the part worth insisting on: a row
 * with two answers is a row that reads differently depending on which column
 * the query happens to look at, and nothing downstream could tell which one the
 * person typed.
 *
 * There is no `value_json`. docs/02 §9 lists one, and it belongs to the
 * multi-value types this slice does not ship — a column nothing can write is a
 * defect this project has already paid for once (`approvals.submission_note`
 * was populated by the seed and by nothing else for four phases, so every
 * screenshot looked right and production was blank). It arrives with the type
 * that needs it.
 *
 * ## The subject is a real foreign key, not a polymorphic pair
 *
 * docs/02 §9 sketches `entity_type, entity_id`. This table instead carries a
 * nullable `work_item_id` and a nullable `project_id`, exactly one non-null,
 * each a composite FK that CASCADEs — the deviation recorded in ADR 0038.
 *
 * The reason is that a polymorphic pair cannot be declared to the database, so
 * deleting a work item would leave its values behind, addressed to an id that
 * no longer exists and belonging to nobody. This product deletes work items;
 * the orphan is not hypothetical. The alternative was application code that
 * remembers to sweep — which is the class of promise docs/03 §8 rule 5 already
 * refuses to make anywhere else.
 *
 * The cost is one nullable column per new subject type. That cost is paid at
 * the rate the product grows subjects, which is roughly never, and it buys a
 * guarantee that no amount of discipline can.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── definitions ─────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE custom_field_definitions (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,

                -- What the field hangs off. Not a free string: a definition
                -- scoped to something with no value column could never hold a
                -- value, and would be a field that silently does nothing.
                scope              varchar(20)   NOT NULL,

                -- The machine name, and the half of this row that is FROZEN.
                -- docs/05 §4 publishes `filter[cf_<key>]`, so a key is part of
                -- the API surface the moment it exists: renaming one breaks
                -- every saved link and every integration that filters on it.
                -- The label is the half that may change, which is why they are
                -- two columns and not one.
                key                varchar(40)   NOT NULL,
                label              varchar(80)   NOT NULL,

                type               varchar(20)   NOT NULL,

                -- Per-type settings: the option list for a select, min/max for
                -- a number. Typed by the application, not here, because the
                -- shape differs per type and a CHECK over JSON would encode the
                -- validator twice.
                config             jsonb         NOT NULL DEFAULT '{}'::jsonb,

                -- Required of a NEW value. It cannot be retroactive: turning it
                -- on would make every existing item invalid without anybody
                -- editing one, and a validation error on a screen the person
                -- did not change is indistinguishable from a bug.
                required           boolean       NOT NULL DEFAULT false,

                position           integer       NOT NULL DEFAULT 0,

                -- Retired, not deleted. A field that stops being collected
                -- still has to read on the items that carry it, so the form
                -- drops it and the record keeps it. DELETE stays available for
                -- the one honest case — created by mistake, never used — and
                -- takes the values with it.
                archived_at        timestamptz   NULL,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_cfd_scope
                    CHECK (scope IN ('work_item', 'project')),

                -- Four types, and the boundary is deliberate. Each one costs a
                -- validator, an input, a renderer, a filter operator and a sort
                -- order; shipping eight badly is how a field type ends up
                -- storing "yes" as text. `multi_select` and `checkbox` arrive
                -- with `value_json` and the filter semantics they need.
                CONSTRAINT ck_cfd_type
                    CHECK (type IN ('text', 'number', 'date', 'select')),

                -- The key has to survive being written into a URL and read back
                -- as one token: `filter[cf_client]` cannot tell a dash from the
                -- rest of the grammar, and a leading digit is not a name.
                CONSTRAINT ck_cfd_key_shape
                    CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),

                CONSTRAINT ck_cfd_position_positive
                    CHECK (position >= 0)
            );

            -- One key per scope per organization — including archived ones. A
            -- retired field still owns its name, because values addressed to it
            -- are still being read, and a new field reusing the name would make
            -- two different questions answer as one column.
            CREATE UNIQUE INDEX uq_cfd_key
                ON custom_field_definitions (organization_id, scope, key);

            -- The form asks for "every live field for this scope, in order" on
            -- every work item open.
            CREATE INDEX idx_cfd_live
                ON custom_field_definitions (organization_id, scope, position)
                WHERE archived_at IS NULL;

            CREATE UNIQUE INDEX uq_custom_field_definitions_org_id
                ON custom_field_definitions (organization_id, id);
        SQL);

        // ── values ──────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE custom_field_values (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,
                definition_id      uuid          NOT NULL,

                -- Exactly one of these. See the class docblock: the pair is an
                -- exclusive arc rather than a polymorphic (type, id), so the
                -- database can be told what a value belongs to.
                work_item_id       uuid          NULL,
                project_id         uuid          NULL,

                value_text         text          NULL,
                -- numeric, not double: money and quantities are the reason
                -- anybody adds a number field, and binary floating point loses
                -- the cent that makes a total wrong by a penny nobody can find.
                value_number       numeric(18,4) NULL,
                value_date         date          NULL,

                created_at         timestamptz   NOT NULL DEFAULT now(),
                updated_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_cfv_one_subject
                    CHECK (num_nonnulls(work_item_id, project_id) = 1),

                -- A row exists because somebody answered. "No answer" is the
                -- absence of the row, never a row full of nulls — otherwise
                -- "unanswered" and "answered with nothing" are the same state
                -- and the required check cannot tell them apart.
                CONSTRAINT ck_cfv_one_value
                    CHECK (num_nonnulls(value_text, value_number, value_date) = 1),

                CONSTRAINT fk_cfv_definition
                    FOREIGN KEY (organization_id, definition_id)
                    REFERENCES custom_field_definitions (organization_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_cfv_work_item
                    FOREIGN KEY (organization_id, work_item_id)
                    REFERENCES work_items (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_cfv_project
                    FOREIGN KEY (organization_id, project_id)
                    REFERENCES projects (organization_id, id) ON DELETE CASCADE
            );

            -- One answer per field per subject. Partial, because a nullable
            -- column in a unique index does not constrain the rows where it is
            -- null: without splitting them, every project row would be distinct
            -- from every other project row on the strength of its null
            -- work_item_id.
            CREATE UNIQUE INDEX uq_cfv_one_per_work_item
                ON custom_field_values (organization_id, definition_id, work_item_id)
                WHERE work_item_id IS NOT NULL;
            CREATE UNIQUE INDEX uq_cfv_one_per_project
                ON custom_field_values (organization_id, definition_id, project_id)
                WHERE project_id IS NOT NULL;

            -- Reading one subject's fields: the join every work item page makes.
            CREATE INDEX idx_cfv_by_work_item
                ON custom_field_values (organization_id, work_item_id)
                WHERE work_item_id IS NOT NULL;
            CREATE INDEX idx_cfv_by_project
                ON custom_field_values (organization_id, project_id)
                WHERE project_id IS NOT NULL;

            -- Filtering by a field's value — `filter[cf_client]=Acme`. One
            -- index per typed column, because a filter always knows the
            -- definition and therefore always knows which column it is asking
            -- about; a single index over all three would be three-quarters
            -- dead weight on every insert.
            CREATE INDEX idx_cfv_text
                ON custom_field_values (organization_id, definition_id, value_text)
                WHERE value_text IS NOT NULL;
            CREATE INDEX idx_cfv_number
                ON custom_field_values (organization_id, definition_id, value_number)
                WHERE value_number IS NOT NULL;
            CREATE INDEX idx_cfv_date
                ON custom_field_values (organization_id, definition_id, value_date)
                WHERE value_date IS NOT NULL;

            CREATE UNIQUE INDEX uq_custom_field_values_org_id
                ON custom_field_values (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS custom_field_values CASCADE;
            DROP TABLE IF EXISTS custom_field_definitions CASCADE;
        SQL);
    }
};
