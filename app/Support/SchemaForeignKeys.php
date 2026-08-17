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
}
