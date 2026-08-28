<?php

declare(strict_types=1);

/**
 * Schema verification harness.
 *
 * Runs the real migration files against a real PostgreSQL server without
 * booting Laravel, by stubbing the two framework symbols the migrations touch
 * (the Migration base class and the DB facade's unprepared()).
 *
 * This exists so the schema can be proven — constraints, partial indexes,
 * partitioning, triggers, composite foreign keys — in environments where the
 * full framework is not installed. The migrations remain the single source of
 * truth; this harness only executes them.
 *
 * Usage: php verify-schema.php "pgsql:host=127.0.0.1;dbname=workos" user pass
 */

namespace Illuminate\Database\Migrations {
    abstract class Migration
    {
        abstract public function up(): void;

        abstract public function down(): void;
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static ?\PDO $pdo = null;

        public static int $statements = 0;

        public static function unprepared(string $sql): void
        {
            self::$statements++;
            self::$pdo->exec($sql);
        }

        public static function table(string $table): \Harness\TableShim
        {
            return new \Harness\TableShim($table);
        }
    }
}

namespace Harness {
    /**
     * The minimum of the query builder that migrations legitimately use:
     * bulk insert of reference data, and its inverse on rollback.
     */
    final class TableShim
    {
        public function __construct(private readonly string $table) {}

        /** @param list<array<string, mixed>> $rows */
        public function insert(array $rows): void
        {
            if ($rows === []) {
                return;
            }

            $columns = array_keys($rows[0]);
            $placeholder = '('.implode(',', array_fill(0, count($columns), '?')).')';

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->table,
                implode(',', $columns),
                implode(',', array_fill(0, count($rows), $placeholder)),
            );

            $bindings = [];
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $bindings[] = $row[$column];
                }
            }

            \Illuminate\Support\Facades\DB::$statements++;
            \Illuminate\Support\Facades\DB::$pdo->prepare($sql)->execute($bindings);
        }
    }

    final class MomentShim
    {
        public function toDateTimeString(): string
        {
            return (new \DateTimeImmutable)->format('Y-m-d H:i:s');
        }

        public function __toString(): string
        {
            return $this->toDateTimeString();
        }
    }
}

namespace {
    use Illuminate\Support\Facades\DB;

    function now(): \Harness\MomentShim
    {
        return new \Harness\MomentShim;
    }

    [$script, $dsn, $user, $pass] = $argv + [null, null, null, null];

    DB::$pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $migrations = glob(__DIR__.'/../../apps/api/database/migrations/*.php');
    sort($migrations);

    foreach ($migrations as $file) {
        $migration = require $file;
        $name = basename($file);

        try {
            $migration->up();
            echo "  ok   {$name}\n";
        } catch (Throwable $e) {
            echo "  FAIL {$name}\n       {$e->getMessage()}\n";
            exit(1);
        }
    }

    echo "\n{$script}: ".count($migrations)." migrations, ".DB::$statements." statements executed.\n";
}
