<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── users ────────────────────────────────────────────────────────────
        // Global, not tenant-scoped: one human, one account, many organizations.
        // Users are never hard-deleted — memberships are revoked and the account
        // is deactivated, so history stays readable (docs/03 §0).
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id                      uuid         PRIMARY KEY,
                email                   varchar(255) NOT NULL,
                name                    varchar(160) NOT NULL,
                password_hash           varchar(255) NULL,
                avatar_path             varchar(512) NULL,
                timezone                varchar(64)  NOT NULL DEFAULT 'UTC',
                locale                  varchar(10)  NOT NULL DEFAULT 'en',
                is_platform_admin       boolean      NOT NULL DEFAULT false,
                email_verified_at       timestamptz  NULL,
                last_login_at           timestamptz  NULL,
                mfa_secret_encrypted    text         NULL,
                mfa_enabled_at          timestamptz  NULL,
                mfa_recovery_codes      jsonb        NULL,
                deactivated_at          timestamptz  NULL,
                created_at              timestamptz  NOT NULL DEFAULT now(),
                updated_at              timestamptz  NOT NULL DEFAULT now()
            );

            -- Case-insensitive uniqueness without requiring the citext extension.
            CREATE UNIQUE INDEX uq_users_email ON users (lower(email));
            CREATE INDEX idx_users_platform_admin
                ON users (is_platform_admin) WHERE is_platform_admin = true;
        SQL);

        // ── sessions ─────────────────────────────────────────────────────────
        // Opaque, revocable, server-side. Never a JWT: when a membership is
        // revoked, access must end at that moment, not at token expiry
        // (docs/06 §1). Only the SHA-256 hash is stored.
        DB::unprepared(<<<'SQL'
            CREATE TABLE sessions (
                id              uuid         PRIMARY KEY,
                user_id         uuid         NOT NULL
                                REFERENCES users (id) ON DELETE CASCADE,
                organization_id uuid         NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                token_hash      char(64)     NOT NULL,
                name            varchar(80)  NOT NULL DEFAULT 'web',
                abilities       jsonb        NOT NULL DEFAULT '["*"]'::jsonb,
                ip_address      inet         NULL,
                user_agent      text         NULL,
                last_used_at    timestamptz  NULL,
                expires_at      timestamptz  NOT NULL,
                revoked_at      timestamptz  NULL,
                created_at      timestamptz  NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX uq_sessions_token_hash ON sessions (token_hash);
            CREATE INDEX idx_sessions_user_active
                ON sessions (user_id) WHERE revoked_at IS NULL;
        SQL);

        // ── memberships ──────────────────────────────────────────────────────
        // The tenant join. Everything tenant-scoped ultimately hangs off this.
        DB::unprepared(<<<'SQL'
            CREATE TABLE memberships (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                user_id         uuid         NOT NULL
                                REFERENCES users (id) ON DELETE RESTRICT,
                status          varchar(20)  NOT NULL DEFAULT 'invited',
                invited_at      timestamptz  NULL,
                joined_at       timestamptz  NULL,
                revoked_at      timestamptz  NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_memberships_status
                    CHECK (status IN ('invited', 'active', 'suspended', 'revoked'))
            );

            CREATE UNIQUE INDEX uq_memberships_org_user
                ON memberships (organization_id, user_id);

            -- Composite unique targets exist so child tables can carry a
            -- tenant-checked foreign key: the DATABASE then refuses a
            -- cross-tenant reference, not just the application (docs/03 §0).
            CREATE UNIQUE INDEX uq_memberships_org_id
                ON memberships (organization_id, id);

            CREATE INDEX idx_memberships_active
                ON memberships (organization_id, status) WHERE revoked_at IS NULL;
        SQL);

        // ── permissions (global catalogue) ───────────────────────────────────
        // Seeded by migration, not by a seeder: every environment must have an
        // identical catalogue or authorization means different things per box.
        DB::unprepared(<<<'SQL'
            CREATE TABLE permissions (
                id          uuid         PRIMARY KEY,
                key         varchar(80)  NOT NULL,
                resource    varchar(40)  NOT NULL,
                action      varchar(40)  NOT NULL,
                description varchar(255) NOT NULL DEFAULT '',
                created_at  timestamptz  NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX uq_permissions_key ON permissions (key);
            CREATE INDEX idx_permissions_resource ON permissions (resource);
        SQL);

        // ── roles (per tenant, composable) ───────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE roles (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL
                                REFERENCES organizations (id) ON DELETE CASCADE,
                key             varchar(60)  NOT NULL,
                name            varchar(120) NOT NULL,
                description     varchar(255) NOT NULL DEFAULT '',
                is_system       boolean      NOT NULL DEFAULT false,
                level           smallint     NOT NULL DEFAULT 0,
                created_at      timestamptz  NOT NULL DEFAULT now(),
                updated_at      timestamptz  NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX uq_roles_org_key ON roles (organization_id, key);
            CREATE UNIQUE INDEX uq_roles_org_id  ON roles (organization_id, id);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE role_permissions (
                role_id         uuid NOT NULL
                                REFERENCES roles (id) ON DELETE CASCADE,
                permission_id   uuid NOT NULL
                                REFERENCES permissions (id) ON DELETE CASCADE,

                PRIMARY KEY (role_id, permission_id)
            );

            CREATE INDEX idx_role_permissions_permission
                ON role_permissions (permission_id);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE membership_roles (
                organization_id uuid NOT NULL,
                membership_id   uuid NOT NULL,
                role_id         uuid NOT NULL,
                granted_at      timestamptz NOT NULL DEFAULT now(),

                PRIMARY KEY (membership_id, role_id),

                -- Tenant-checked composite FKs: a membership in org A can never
                -- be granted a role belonging to org B.
                CONSTRAINT fk_membership_roles_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_membership_roles_role
                    FOREIGN KEY (organization_id, role_id)
                    REFERENCES roles (organization_id, id) ON DELETE CASCADE
            );

            CREATE INDEX idx_membership_roles_role ON membership_roles (role_id);
        SQL);

        // ── scoped role assignments ──────────────────────────────────────────
        // "Manager OF project X". Unused at MVP by design — it exists now so
        // that Phase 7 is a feature, not a migration of live permission data
        // (docs/12 §10).
        DB::unprepared(<<<'SQL'
            CREATE TABLE scoped_role_assignments (
                id              uuid         PRIMARY KEY,
                organization_id uuid         NOT NULL,
                membership_id   uuid         NOT NULL,
                role_id         uuid         NOT NULL,
                scope_type      varchar(40)  NOT NULL,
                scope_id        uuid         NOT NULL,
                granted_by      uuid         NULL,
                created_at      timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT ck_sra_scope_type
                    CHECK (scope_type IN ('project', 'team', 'department')),
                CONSTRAINT fk_sra_membership
                    FOREIGN KEY (organization_id, membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_sra_role
                    FOREIGN KEY (organization_id, role_id)
                    REFERENCES roles (organization_id, id) ON DELETE CASCADE
            );

            CREATE UNIQUE INDEX uq_sra_unique_grant
                ON scoped_role_assignments
                   (organization_id, membership_id, role_id, scope_type, scope_id);
            CREATE INDEX idx_sra_scope
                ON scoped_role_assignments (organization_id, scope_type, scope_id);
        SQL);

        // ── invitations & password resets ────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE invitations (
                id                      uuid         PRIMARY KEY,
                organization_id         uuid         NOT NULL
                                        REFERENCES organizations (id) ON DELETE CASCADE,
                email                   varchar(255) NOT NULL,
                role_id                 uuid         NULL,
                invited_by_membership_id uuid        NULL,
                token_hash              char(64)     NOT NULL,
                expires_at              timestamptz  NOT NULL,
                accepted_at             timestamptz  NULL,
                revoked_at              timestamptz  NULL,
                created_at              timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT fk_invitations_role
                    FOREIGN KEY (organization_id, role_id)
                    REFERENCES roles (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_invitations_token ON invitations (token_hash);
            CREATE UNIQUE INDEX uq_invitations_pending
                ON invitations (organization_id, lower(email))
                WHERE accepted_at IS NULL AND revoked_at IS NULL;

            CREATE TABLE password_reset_tokens (
                email       varchar(255) PRIMARY KEY,
                token_hash  char(64)     NOT NULL,
                created_at  timestamptz  NOT NULL DEFAULT now()
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS password_reset_tokens CASCADE;
            DROP TABLE IF EXISTS invitations CASCADE;
            DROP TABLE IF EXISTS scoped_role_assignments CASCADE;
            DROP TABLE IF EXISTS membership_roles CASCADE;
            DROP TABLE IF EXISTS role_permissions CASCADE;
            DROP TABLE IF EXISTS roles CASCADE;
            DROP TABLE IF EXISTS permissions CASCADE;
            DROP TABLE IF EXISTS memberships CASCADE;
            DROP TABLE IF EXISTS sessions CASCADE;
            DROP TABLE IF EXISTS users CASCADE;
        SQL);
    }
};
