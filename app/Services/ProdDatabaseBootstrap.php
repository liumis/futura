<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ProdDatabaseBootstrap
{
    public const SQL_SNAPSHOT_RELATIVE = 'snapshots/prod-bootstrap.sql.gz';

    public const JSON_SNAPSHOT_RELATIVE = 'snapshots/prod-bootstrap.json.gz';

    /**
     * @var list<string>
     */
    public const SKIP_TABLES = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'legacy_data_imports',
        'sqlite_sequence',
    ];

    public static function sqlSnapshotPath(): string
    {
        return database_path(self::SQL_SNAPSHOT_RELATIVE);
    }

    public static function snapshotPath(): string
    {
        return is_file(self::sqlSnapshotPath())
            ? self::sqlSnapshotPath()
            : database_path(self::JSON_SNAPSHOT_RELATIVE);
    }

    public static function hasSnapshot(): bool
    {
        return is_file(self::sqlSnapshotPath())
            || is_file(database_path(self::JSON_SNAPSHOT_RELATIVE));
    }

    public static function dumpChecksum(): ?string
    {
        $path = self::snapshotPath();

        if (! is_file($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }

    public static function alreadyImported(): bool
    {
        if (! Schema::hasTable('legacy_data_imports')) {
            return false;
        }

        $checksum = self::dumpChecksum();

        if ($checksum === null) {
            return DB::table('legacy_data_imports')->exists();
        }

        return DB::table('legacy_data_imports')->where('checksum', $checksum)->exists();
    }

    /**
     * @return array{tables: int, rows: int, path: string}
     */
    public static function exportFromSqlite(?string $sqlitePath = null): array
    {
        $sqlitePath ??= database_path('database.sqlite');

        if (! is_file($sqlitePath)) {
            throw new RuntimeException('SQLite database not found at '.$sqlitePath);
        }

        $config = config('database.connections.sqlite');
        $config['database'] = $sqlitePath;
        config(['database.connections.sqlite_snapshot' => $config]);

        $connection = DB::connection('sqlite_snapshot');

        $tables = collect($connection->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ))->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, self::SKIP_TABLES, true))
            ->values()
            ->all();

        $sql = [
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET UNIQUE_CHECKS=0;',
        ];

        $rowCount = 0;

        foreach ($tables as $table) {
            $quotedTable = self::quoteIdentifier($table);
            $sql[] = 'DELETE FROM '.$quotedTable.';';

            $rows = $connection->table($table)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $columns = array_keys((array) $rows->first());
            $quotedColumns = implode(', ', array_map(self::quoteIdentifier(...), $columns));

            foreach ($rows->chunk(50) as $chunk) {
                $values = [];

                foreach ($chunk as $row) {
                    $rowCount++;
                    $cells = [];

                    foreach ($columns as $column) {
                        $cells[] = self::toSqlLiteral(((array) $row)[$column] ?? null);
                    }

                    $values[] = '('.implode(', ', $cells).')';
                }

                $sql[] = 'INSERT INTO '.$quotedTable.' ('.$quotedColumns.') VALUES '.implode(', ', $values).';';
            }
        }

        $sql[] = 'SET UNIQUE_CHECKS=1;';
        $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';

        $directory = dirname(self::sqlSnapshotPath());
        File::ensureDirectoryExists($directory);

        $encoded = gzencode(implode("\n", $sql)."\n", 9);

        if ($encoded === false) {
            throw new RuntimeException('Failed to gzip the MySQL dump.');
        }

        File::put(self::sqlSnapshotPath(), $encoded);

        return [
            'tables' => count($tables),
            'rows' => $rowCount,
            'path' => self::sqlSnapshotPath(),
        ];
    }

    /**
     * @return array{tables: int, rows: int}
     */
    public static function importToMysql(bool $force = false): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Prod bootstrap import only runs on MySQL/MariaDB (current: '.$driver.').');
        }

        if (! $force && self::alreadyImported()) {
            throw new RuntimeException('This dump was already imported. Use --force to replace it.');
        }

        if (is_file(self::sqlSnapshotPath())) {
            return self::importSqlDump($force);
        }

        return self::importJsonSnapshot($force);
    }

    /**
     * @return array{tables: int, rows: int}
     */
    private static function importSqlDump(bool $force): array
    {
        $decoded = gzdecode((string) File::get(self::sqlSnapshotPath()));

        if ($decoded === false) {
            throw new RuntimeException('Could not read gzip MySQL dump.');
        }

        $statements = preg_split('/;\s*\R/', $decoded) ?: [];
        $executed = 0;
        $touched = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($statements as $statement) {
                $statement = trim($statement);

                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }

                $adapted = self::adaptStatementForMysql($statement);

                if ($adapted === null) {
                    continue;
                }

                DB::unprepared($adapted);
                $executed++;

                if (preg_match('/^INSERT INTO `([^`]+)`/i', $adapted, $match) === 1) {
                    $touched[$match[1]] = true;
                }
            }

            foreach (array_keys($touched) as $table) {
                self::resetAutoIncrement($table);
            }

            self::recordImport('mysql-dump', $force);
        } catch (Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            throw $e;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return [
            'tables' => $executed,
            'rows' => $executed,
        ];
    }

    /**
     * @return array{tables: int, rows: int}
     */
    private static function importJsonSnapshot(bool $force): array
    {
        $path = database_path(self::JSON_SNAPSHOT_RELATIVE);

        if (! is_file($path)) {
            throw new RuntimeException('Snapshot missing: '.$path);
        }

        $decoded = gzdecode((string) File::get($path));

        if ($decoded === false) {
            throw new RuntimeException('Could not read gzip snapshot.');
        }

        /** @var array{tables?: array<string, list<array<string, mixed>>>} $payload */
        $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        $tables = $payload['tables'] ?? [];

        if ($tables === []) {
            throw new RuntimeException('Snapshot contains no tables.');
        }

        $importedTables = 0;
        $importedRows = 0;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            DB::transaction(function () use ($tables, $force, &$importedTables, &$importedRows): void {
                foreach ($tables as $table => $rows) {
                    if (! Schema::hasTable($table) || in_array($table, self::SKIP_TABLES, true)) {
                        continue;
                    }

                    $columns = Schema::getColumnListing($table);
                    DB::table($table)->delete();

                    foreach (array_chunk($rows, 100) as $chunk) {
                        $prepared = [];

                        foreach ($chunk as $row) {
                            $filtered = [];

                            foreach ($row as $column => $value) {
                                if (! in_array($column, $columns, true)) {
                                    continue;
                                }

                                $filtered[$column] = $value;
                            }

                            if ($filtered !== []) {
                                $prepared[] = $filtered;
                            }
                        }

                        if ($prepared !== []) {
                            DB::table($table)->insert($prepared);
                            $importedRows += count($prepared);
                        }
                    }

                    $importedTables++;
                    self::resetAutoIncrement($table);
                }

                self::recordImport('sqlite-snapshot', $force);
            });
        } catch (Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            throw $e;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return [
            'tables' => $importedTables,
            'rows' => $importedRows,
        ];
    }

    private static function recordImport(string $source, bool $force): void
    {
        if (! Schema::hasTable('legacy_data_imports')) {
            return;
        }

        if ($force) {
            DB::table('legacy_data_imports')->delete();
        }

        $payload = [
            'source' => $source,
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('legacy_data_imports', 'checksum')) {
            $payload['checksum'] = self::dumpChecksum();
        }

        DB::table('legacy_data_imports')->insert($payload);
    }

    private static function resetAutoIncrement(string $table): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            return;
        }

        $max = (int) DB::table($table)->max('id');
        $next = $max > 0 ? $max + 1 : 1;
        $quoted = self::quoteIdentifier($table);

        DB::statement("ALTER TABLE {$quoted} AUTO_INCREMENT = {$next}");
    }

    /**
     * SQLite may still have columns that MySQL migrations already dropped.
     * Skip missing tables and drop unknown columns from INSERT statements.
     */
    private static function adaptStatementForMysql(string $statement): ?string
    {
        if (preg_match('/^DELETE FROM `([^`]+)`$/i', $statement, $match) === 1) {
            $table = $match[1];

            if (! Schema::hasTable($table) || in_array($table, self::SKIP_TABLES, true)) {
                return null;
            }

            return $statement;
        }

        if (preg_match('/^INSERT INTO `([^`]+)` \((.+)\) VALUES\s+(.+)$/is', $statement, $match) !== 1) {
            return $statement;
        }

        $table = $match[1];

        if (! Schema::hasTable($table) || in_array($table, self::SKIP_TABLES, true)) {
            return null;
        }

        preg_match_all('/`([^`]+)`/', $match[2], $columnMatches);
        $dumpColumns = $columnMatches[1];
        $schemaColumns = Schema::getColumnListing($table);
        $keepIndexes = [];
        $keepColumns = [];

        foreach ($dumpColumns as $index => $column) {
            if (in_array($column, $schemaColumns, true)) {
                $keepIndexes[] = $index;
                $keepColumns[] = $column;
            }
        }

        if ($keepColumns === []) {
            return null;
        }

        if (count($keepColumns) === count($dumpColumns)) {
            return $statement;
        }

        $rows = self::parseSqlValueGroups($match[3]);
        $values = [];

        foreach ($rows as $row) {
            if (count($row) !== count($dumpColumns)) {
                throw new RuntimeException("Dump row for {$table} has ".count($row).' values but '.count($dumpColumns).' columns.');
            }

            $cells = [];

            foreach ($keepIndexes as $index) {
                $cells[] = $row[$index];
            }

            $values[] = '('.implode(', ', $cells).')';
        }

        $quotedColumns = implode(', ', array_map(self::quoteIdentifier(...), $keepColumns));

        return 'INSERT INTO '.self::quoteIdentifier($table).' ('.$quotedColumns.') VALUES '.implode(', ', $values);
    }

    /**
     * @return list<list<string>>
     */
    private static function parseSqlValueGroups(string $sql): array
    {
        $rows = [];
        $currentRow = [];
        $current = '';
        $inString = false;
        $depth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[$i + 1];
                    $i++;

                    continue;
                }

                if ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                if ($depth === 0) {
                    $depth = 1;
                    $current = '';
                    $currentRow = [];

                    continue;
                }

                $depth++;
                $current .= $char;

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    $currentRow[] = trim($current);
                    $rows[] = $currentRow;
                    $current = '';
                    $currentRow = [];

                    continue;
                }

                $current .= $char;

                continue;
            }

            if ($char === ',' && $depth === 1) {
                $currentRow[] = trim($current);
                $current = '';

                continue;
            }

            if ($depth >= 1) {
                $current .= $char;
            }
        }

        return $rows;
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private static function toSqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        return "'".str_replace(
            ["\\", "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $string,
        )."'";
    }
}
