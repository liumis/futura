<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ProdDatabaseBootstrap
{
    public const SNAPSHOT_RELATIVE = 'snapshots/prod-bootstrap.json.gz';

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

    public static function snapshotPath(): string
    {
        return database_path(self::SNAPSHOT_RELATIVE);
    }

    public static function hasSnapshot(): bool
    {
        return is_file(self::snapshotPath());
    }

    public static function alreadyImported(): bool
    {
        if (! Schema::hasTable('legacy_data_imports')) {
            return false;
        }

        return DB::table('legacy_data_imports')->exists();
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

        $tables = collect(DB::connection('sqlite_snapshot')->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ))->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, self::SKIP_TABLES, true))
            ->values()
            ->all();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'source' => 'sqlite',
            'tables' => [],
        ];

        $rowCount = 0;

        foreach ($tables as $table) {
            $rows = DB::connection('sqlite_snapshot')->table($table)->get()->map(
                fn ($row): array => (array) $row
            )->all();

            $payload['tables'][$table] = $rows;
            $rowCount += count($rows);
        }

        $directory = dirname(self::snapshotPath());
        File::ensureDirectoryExists($directory);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encoded = gzencode($json, 9);

        if ($encoded === false) {
            throw new RuntimeException('Failed to gzip the SQLite snapshot.');
        }

        File::put(self::snapshotPath(), $encoded);

        return [
            'tables' => count($payload['tables']),
            'rows' => $rowCount,
            'path' => self::snapshotPath(),
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
            throw new RuntimeException('Legacy data was already imported. Use --force to replace it.');
        }

        if (! self::hasSnapshot()) {
            throw new RuntimeException('Snapshot missing: '.self::snapshotPath());
        }

        $decoded = gzdecode((string) File::get(self::snapshotPath()));

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
                if ($force && Schema::hasTable('legacy_data_imports')) {
                    DB::table('legacy_data_imports')->delete();
                }

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

                if (Schema::hasTable('legacy_data_imports')) {
                    DB::table('legacy_data_imports')->insert([
                        'source' => 'sqlite-snapshot',
                        'imported_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
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

    private static function resetAutoIncrement(string $table): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            return;
        }

        $max = (int) DB::table($table)->max('id');
        $next = $max > 0 ? $max + 1 : 1;
        $quoted = '`'.str_replace('`', '``', $table).'`';

        DB::statement("ALTER TABLE {$quoted} AUTO_INCREMENT = {$next}");
    }
}
