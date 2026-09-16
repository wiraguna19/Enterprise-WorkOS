<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Taking one permission away from one person (docs/06 §2, ADR 0020).
 *
 * Every permission in this product has been a GRANT since Phase 1:
 * `PermissionResolver` unions what the roles give and never subtracts. That is
 * the right default — an authorization model where anything can take anything
 * away is a model nobody can reason about — and it leaves one shape
 * unexpressible: "they are an Employee, and this one person may not export".
 *
 * The narrow form, deliberately:
 *
 * - **Per MEMBERSHIP, never per role.** A deny inside a role means two roles
 *   can disagree, and the answer to "why can't I" becomes a graph traversal.
 *   Here the answer is always one row, and the row has a reason written on it.
 * - **Optionally scoped**, matching `scoped_role_assignments`: "not on this
 *   project" is the case that actually comes up.
 * - **Deny wins.** A denial defeats every grant, org-wide or scoped, because a
 *   deny that a later grant could silently overturn is a deny nobody can trust.
 *
 * `reason` is NOT NULL and not defaulted. An entry with no reason is a mystery
 * six months later, and the person hitting the wall cannot be told anything
 * useful about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE permission_denials (
                id                 uuid          PRIMARY KEY,
                organization_id    uuid          NOT NULL
                                   REFERENCES organizations (id) ON DELETE CASCADE,
                membership_id      uuid          NOT NULL,

                -- The permission KEY, not an id. `permissions` is a global
                -- catalogue with its own ids, and a denial that outlived a
                -- reseeded catalogue row would point at nothing; the key is
                -- what every other layer of this product says out loud.
                permission_key     varchar(80)   NOT NULL,

                -- Null means "everywhere". A scoped denial mirrors
                -- scoped_role_assignments and its CHECK.
                scope_type         varchar(40)   NULL,
                scope_id           uuid          NULL,

                -- Required. A denial with no reason is a wall with no sign on
                -- it, and this column is what the refusal can quote.
                reason             varchar(255)  NOT NULL,

                denied_by          uuid          NULL,
                created_at         timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_denials_scope_type
                    CHECK (scope_type IS NULL OR scope_type IN ('project', 'team', 'department')),
                CONSTRAINT ck_denials_scope_pair
                    CHECK ((scope_type IS NULL) = (scope_id IS NULL)),

                CONSTRAINT fk_denials_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE
            );

            -- One denial per person per permission per scope. The COALESCE is
            -- how a nullable pair takes part in a unique index at all:
            -- (null, null) is distinct from itself in SQL, so without it a
            -- person could be denied the same thing everywhere twice.
            CREATE UNIQUE INDEX uq_denials_unique
                ON permission_denials (
                    organization_id, membership_id, permission_key,
                    COALESCE(scope_type, ''),
                    COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid)
                );

            -- The resolver reads every denial for one membership on the hot
            -- path, so this is the index that keeps a deny from costing a scan.
            CREATE INDEX idx_denials_membership
                ON permission_denials (organization_id, membership_id);

            CREATE UNIQUE INDEX uq_denials_org_id
                ON permission_denials (organization_id, id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS permission_denials CASCADE;');
    }
};
