<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A person's reading size (docs/09 §2, accessibility).
 *
 * One of three steps rather than a free number: each is a size the interface
 * is laid out and tested at, and a slider would offer every size in between
 * that nobody has looked at. Stored on the user, like the language, so it
 * follows the person to every device they sign in on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN IF NOT EXISTS text_size varchar(10) NOT NULL DEFAULT 'normal';

            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_text_size;
            ALTER TABLE users ADD CONSTRAINT ck_users_text_size
                CHECK (text_size IN ('normal', 'large', 'larger'));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_text_size;
            ALTER TABLE users DROP COLUMN IF EXISTS text_size;
            SQL);
    }
};
