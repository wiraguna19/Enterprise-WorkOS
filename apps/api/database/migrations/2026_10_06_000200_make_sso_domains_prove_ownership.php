<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An organization proves it owns an email domain before its identity
 * provider signs that domain in.
 *
 * Until now the first organization to type a domain owned it: uq_ssod_domain
 * was unique across every organization and nothing asked whether the claim
 * was true. Anybody could take a competitor's domain, and the competitor
 * could then not configure single sign-on at all.
 *
 * A claim is now a pending one until a DNS TXT record proves it. Only a
 * VERIFIED domain is unique across organizations — so a squatter's pending
 * claim blocks nobody — and only a verified domain is used to sign anyone in.
 *
 * Domains claimed before this are treated as verified: they were configured
 * by hand and are what live sign-ins already depend on. Re-verifying them
 * would switch single sign-on off under organizations that require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE sso_domains
                ADD COLUMN verification_token varchar(64) NOT NULL
                    DEFAULT md5(random()::text || clock_timestamp()::text),
                ADD COLUMN verified_at timestamptz NULL;

            UPDATE sso_domains SET verified_at = created_at;

            DROP INDEX uq_ssod_domain;

            -- One address, one IdP — among domains somebody has PROVED.
            CREATE UNIQUE INDEX uq_ssod_domain_verified
                ON sso_domains (domain) WHERE verified_at IS NOT NULL;

            -- And one claim per domain within an organization.
            CREATE UNIQUE INDEX uq_ssod_org_domain
                ON sso_domains (organization_id, domain);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX uq_ssod_org_domain;
            DROP INDEX uq_ssod_domain_verified;
            DELETE FROM sso_domains WHERE verified_at IS NULL;
            CREATE UNIQUE INDEX uq_ssod_domain ON sso_domains (domain);
            ALTER TABLE sso_domains DROP COLUMN verified_at, DROP COLUMN verification_token;
        SQL);
    }
};
