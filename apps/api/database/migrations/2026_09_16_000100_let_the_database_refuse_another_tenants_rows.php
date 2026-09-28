<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL Row-Level Security, as a second tenant boundary (docs/01 §6,
 * docs/12 §4, ADR 0051).
 *
 * docs/01 §6 promised the schema was "RLS-compatible: every tenant table has a
 * non-nullable `organization_id` … Turning RLS on later is a migration plus a
 * session-variable setter in the DB connection — days, not a rewrite." This is
 * the migration; `PostgresRowLevelSecurity` is the setter.
 *
 * ## The policies apply to one role, and only while a tenant is bound
 *
 * `workos_tenant` is a role nobody logs in as. The application switches to it
 * (`SET ROLE`) when an organization is bound and back when none is, and every
 * policy below is written `TO workos_tenant`. So:
 *
 * - a request or job acting FOR an organization is held to that organization
 *   by the database, whatever its query forgot;
 * - migrations, seeders, the scheduler reading across tenants, and platform
 *   mode run as the connecting role and are untouched — they already had to
 *   say they cross tenants, and they keep working without a list of
 *   exceptions here.
 *
 * ## Which tables
 *
 * Every table — not partition — with a NOT NULL `organization_id`, found by
 * asking the catalogue rather than by a list, because a list is what goes
 * stale: `ArchitectureTest` learned in ADR 0046 that "enumerate what is
 * covered" is a guard that stops guarding the day somebody adds a table.
 * `RowLevelSecurityTest` asks the same catalogue question and fails for any
 * tenant table without a policy, including ones added after today.
 *
 * Tables whose `organization_id` is NULLABLE are left out on purpose:
 * `sessions` is read before any tenant is known — it is how the tenant is
 * found — and `audit_logs` holds platform events that belong to no
 * organization. Partitions are left out because a query through the parent is
 * governed by the parent's policy, and `EnsureLogPartitions` makes new ones
 * every month.
 *
 * ## Grants
 *
 * `workos_tenant` gets DML on every table and sequence, and — through default
 * privileges — on every one created later by the role that runs migrations.
 * It gets nothing else: no DDL, no ownership, no BYPASSRLS. The connecting
 * role is granted membership so it may `SET ROLE`; a superuser needs no grant,
 * a production login role does.
 *
 * Roles are cluster-wide, so the role is created if absent rather than
 * unconditionally: the development and test databases share a cluster, and
 * the second to migrate would otherwise fail on a role the first made.
 */
return new class extends Migration
{
    /**
     * The one predicate, in one place. NULLIF because an unset setting reads as
     * the empty string, and `''::uuid` is an error where "no organization"
     * should simply match nothing.
     */
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'workos_tenant') THEN
                    CREATE ROLE workos_tenant NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT;
                END IF;
            END
            $$;

            GRANT workos_tenant TO CURRENT_USER;

            GRANT USAGE ON SCHEMA public TO workos_tenant;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO workos_tenant;
            GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO workos_tenant;

            ALTER DEFAULT PRIVILEGES IN SCHEMA public
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO workos_tenant;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public
                GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO workos_tenant;
        SQL);

        foreach ($this->tenantTables() as $table) {
            DB::unprepared(sprintf(
                'ALTER TABLE %1$s ENABLE ROW LEVEL SECURITY;'
                .' DROP POLICY IF EXISTS tenant_isolation ON %1$s;'
                .' CREATE POLICY tenant_isolation ON %1$s TO workos_tenant USING (%2$s) WITH CHECK (%2$s);',
                $this->quote($table),
                self::PREDICATE,
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->tenantTables() as $table) {
            DB::unprepared(sprintf(
                'DROP POLICY IF EXISTS tenant_isolation ON %1$s; ALTER TABLE %1$s DISABLE ROW LEVEL SECURITY;',
                $this->quote($table),
            ));
        }

        // The role stays: it is cluster-wide, and another database on the same
        // server may still depend on it.
    }

    /**
     * Every ordinary or partitioned table, not a partition, with a NOT NULL
     * `organization_id`. The same question `RowLevelSecurityTest` asks.
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = [];

        foreach (DB::select(<<<'SQL'
            SELECT c.relname
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN pg_attribute a ON a.attrelid = c.oid
                                 AND a.attname = 'organization_id'
                                 AND a.attnotnull
                                 AND NOT a.attisdropped
             WHERE n.nspname = 'public'
               AND c.relkind IN ('r', 'p')
               AND NOT c.relispartition
             ORDER BY c.relname
        SQL) as $row) {
            $tables[] = (string) $row->relname;
        }

        return $tables;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
