<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaForeignKeys
{
    /**
     * @return list<string>
     */
    public static function namesOnColumn(string $table, string $column): array
    {
        $names = [];

        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (in_array($column, array_map('trim', $foreign['columns'] ?? []), true) && filled($foreign['name'] ?? null)) {
                $names[] = $foreign['name'];
            }
        }

        return array_values(array_unique($names));
    }

    public static function dropOnColumn(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $safeTable = str_replace('`', '', $table);

        foreach (self::namesOnColumn($table, $column) as $name) {
            $safeName = str_replace('`', '', $name);

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$safeTable}` DROP FOREIGN KEY `{$safeName}`");

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($safeName): void {
                $blueprint->dropForeign($safeName);
            });
        }
    }

    public static function dropColumnIfExists(string $table, string ...$columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasTable($table) && Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            self::sqliteDropColumns($table, $existing);

            return;
        }

        foreach ($existing as $column) {
            self::dropOnColumn($table, $column);
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }

    /**
     * SQLite cannot ALTER DROP COLUMN when a foreign key / index still references the column.
     * Rebuild the table without those columns while preserving PRIMARY KEY on id.
     *
     * @param  list<string>  $columnsToDrop
     */
    public static function sqliteDropColumns(string $table, array $columnsToDrop): void
    {
        $columnsToDrop = array_values(array_filter(
            $columnsToDrop,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columnsToDrop === []) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $keep = array_values(array_diff(Schema::getColumnListing($table), $columnsToDrop));
            $tmp = $table.'__fk_drop_tmp';
            $safeTable = str_replace('"', '""', $table);
            $safeTmp = str_replace('"', '""', $tmp);

            Schema::dropIfExists($tmp);

            $columnDefs = [];
            foreach (DB::select("PRAGMA table_info(\"{$safeTable}\")") as $info) {
                $name = (string) $info->name;

                if (in_array($name, $columnsToDrop, true)) {
                    continue;
                }

                $quoted = '"'.str_replace('"', '""', $name).'"';

                if ((int) $info->pk === 1 && $name === 'id') {
                    $columnDefs[] = "{$quoted} INTEGER PRIMARY KEY AUTOINCREMENT";

                    continue;
                }

                $type = filled($info->type) ? (string) $info->type : 'TEXT';
                $nullSql = ((int) $info->notnull === 1) ? 'NOT NULL' : 'NULL';
                $defaultSql = '';

                if ($info->dflt_value !== null) {
                    $defaultSql = ' DEFAULT '.$info->dflt_value;
                }

                $columnDefs[] = "{$quoted} {$type} {$nullSql}{$defaultSql}";
            }

            DB::statement('CREATE TABLE "'.$safeTmp.'" ('.implode(', ', $columnDefs).')');

            if ($keep !== []) {
                $quotedKeep = implode(', ', array_map(
                    static fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
                    $keep,
                ));

                DB::statement("INSERT INTO \"{$safeTmp}\" ({$quotedKeep}) SELECT {$quotedKeep} FROM \"{$safeTable}\"");
            }

            if (in_array('id', $keep, true)) {
                $maxId = (int) (DB::table($table)->max('id') ?? 0);

                if ($maxId > 0) {
                    try {
                        DB::table('sqlite_sequence')->where('name', $tmp)->delete();
                        DB::table('sqlite_sequence')->insert(['name' => $tmp, 'seq' => $maxId]);
                    } catch (\Throwable) {
                        // sqlite_sequence may not exist yet
                    }
                }
            }

            Schema::drop($table);
            Schema::rename($tmp, $table);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public static function hasOnColumn(string $table, string $column): bool
    {
        return self::namesOnColumn($table, $column) !== [];
    }

    public static function ensure(
        string $table,
        string $column,
        string $onTable,
        string $name,
        string $onDelete = 'cascade',
        string $onColumn = 'id',
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || self::hasOnColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $onTable, $name, $onDelete, $onColumn): void {
            $foreign = $blueprint->foreign($column, $name)->references($onColumn)->on($onTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($onDelete === 'null') {
                $foreign->nullOnDelete();
            } elseif ($onDelete === 'restrict') {
                $foreign->restrictOnDelete();
            }
        });
    }
}
