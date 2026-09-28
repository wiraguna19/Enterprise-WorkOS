<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAML single sign-on (docs/10 Phase 7 "SSO/SAML", ADR 0052).
 *
 * ## One identity provider per organization
 *
 * `organization_id` is UNIQUE on the connection. An organization with two IdPs
 * is real — a merger, a contractor directory — and it is also the case where
 * "which one signs this person in" becomes a question the product has to
 * answer on every sign-in. One is what every organization in this product
 * needs today, and the constraint says so out loud rather than leaving a
 * second row to be ignored by code that reads `first()`.
 *
 * ## Domains are a table, and unique across the whole product
 *
 * Sign-in starts from an address (`rina@acme.com` → Acme's IdP), so a domain
 * must lead to one organization and only one. That is a uniqueness constraint
 * ACROSS tenants, which no per-organization array can express — hence a row
 * per domain with a global unique index. It also bounds what an IdP can vouch
 * for: an assertion for an address outside the connection's domains is
 * refused, so one organization's IdP cannot sign somebody in by an address
 * another organization owns.
 *
 * Claiming a domain does not yet require PROVING it (a DNS record). ADR 0052
 * names that as owed and says what bounds the risk meanwhile.
 *
 * ## `last_succeeded_at` is what makes enforcement safe
 *
 * Requiring SSO of everybody with a connection that has never worked would
 * lock the organization out on a typo in a certificate. The service refuses to
 * enforce until somebody has actually signed in through it, and this column
 * is how it knows.
 *
 * ## `sessions.authenticated_by`
 *
 * A session knows HOW it was proved, because three rules depend on it: a
 * session proved by an organization's IdP is exempt from that organization's
 * second-factor requirement (the IdP is where the factor lives), cannot be
 * used to reach a DIFFERENT organization (that IdP vouches for nobody else),
 * and cannot change the account's own second factor. Every existing row was a
 * password.
 *
 * ## Row-Level Security
 *
 * Both tables carry a NOT NULL `organization_id`, so both get the policy every
 * tenant table has (ADR 0051) — here, in the migration that creates them, which
 * is what `RowLevelSecurityTest` asks of every table added after it.
 */
return new class extends Migration
{
    private const PREDICATE = "organization_id = NULLIF(current_setting('app.organization_id', true), '')::uuid";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sso_connections (
                id                  uuid          PRIMARY KEY,
                organization_id     uuid          NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,

                -- What the IdP's metadata calls itself, and where to send the
                -- browser. Both are compared, not merely stored: a response
                -- whose Issuer is not this entity id is refused.
                idp_entity_id       varchar(500)  NOT NULL,
                idp_sso_url         varchar(2000) NOT NULL,

                -- PEM. Public by nature — it is the half of the IdP's key pair
                -- that anybody may hold — so it is stored as text, not
                -- encrypted like a webhook secret.
                idp_certificate     text          NOT NULL,

                -- Password sign-in into this organization is refused while
                -- true, except for the people who administer this connection
                -- (ADR 0052's break-glass).
                enforced            boolean       NOT NULL DEFAULT false,

                last_succeeded_at   timestamptz   NULL,

                created_by_membership_id uuid     NULL,
                created_at          timestamptz   NOT NULL DEFAULT now(),
                updated_at          timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_ssoc_https
                    CHECK (idp_sso_url ~ '^https://'),
                CONSTRAINT ck_ssoc_enforce_tested
                    CHECK (NOT enforced OR last_succeeded_at IS NOT NULL),
                CONSTRAINT fk_ssoc_creator
                    FOREIGN KEY (organization_id, created_by_membership_id)
                    REFERENCES memberships (organization_id, id) ON DELETE SET NULL
            );

            CREATE UNIQUE INDEX uq_ssoc_one_per_organization
                ON sso_connections (organization_id);

            CREATE UNIQUE INDEX uq_sso_connections_org_id
                ON sso_connections (organization_id, id);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TABLE sso_domains (
                id                  uuid          PRIMARY KEY,
                organization_id     uuid          NOT NULL
                                    REFERENCES organizations (id) ON DELETE CASCADE,
                connection_id       uuid          NOT NULL,

                -- Lower-case, no @, no scheme: "acme.com". The service
                -- normalises; the CHECK refuses what slipped past it.
                domain              varchar(253)  NOT NULL,

                created_at          timestamptz   NOT NULL DEFAULT now(),

                CONSTRAINT ck_ssod_domain
                    CHECK (domain = lower(domain) AND domain ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'),
                CONSTRAINT fk_ssod_connection
                    FOREIGN KEY (organization_id, connection_id)
                    REFERENCES sso_connections (organization_id, id) ON DELETE CASCADE
            );

            -- ACROSS organizations, deliberately: one address, one IdP.
            CREATE UNIQUE INDEX uq_ssod_domain
                ON sso_domains (domain);

            CREATE UNIQUE INDEX uq_sso_domains_org_id
                ON sso_domains (organization_id, id);
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE sessions
                ADD COLUMN authenticated_by varchar(20) NOT NULL DEFAULT 'password';

            ALTER TABLE sessions ADD CONSTRAINT ck_sessions_authenticated_by
                CHECK (authenticated_by IN ('password', 'sso'));
        SQL);

        foreach (['sso_connections', 'sso_domains'] as $table) {
            DB::unprepared(sprintf(
                'ALTER TABLE %1$s ENABLE ROW LEVEL SECURITY;'
                .' CREATE POLICY tenant_isolation ON %1$s TO workos_tenant USING (%2$s) WITH CHECK (%2$s);',
                $table,
                self::PREDICATE,
            ));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sessions DROP CONSTRAINT IF EXISTS ck_sessions_authenticated_by;
            ALTER TABLE sessions DROP COLUMN IF EXISTS authenticated_by;
            DROP TABLE IF EXISTS sso_domains CASCADE;
            DROP TABLE IF EXISTS sso_connections CASCADE;
        SQL);
    }
};
