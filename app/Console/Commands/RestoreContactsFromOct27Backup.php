<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RestoreContactsFromOct27Backup extends Command
{
    protected $signature = 'app:restore-contacts-from-oct27-backup
                            {--force : Run even if already restored}
                            {--path= : Optional path to the SQL dump}';

    protected $description = 'Replace contacts / directory contacts / stages / persons / comments from the Oct 27 Cloud backup dump';

    /**
     * @var list<string>
     */
    private const TABLES = [
        'directory_contact_comments',
        'directory_contact_persons',
        'directory_contacts',
        'directory_contact_stages',
        'contacts',
    ];

    public function handle(): int
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error('This command only runs on MySQL/MariaDB (current: '.$driver.').');

            return self::FAILURE;
        }

        $path = $this->option('path') ?: database_path('snapshots/contacts-restore-oct27.sql');

        if (! is_file($path)) {
            $this->error('Dump not found: '.$path);

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->alreadyRestored()) {
            $this->info('Contacts restore already applied; skipping. Use --force to re-run.');

            return self::SUCCESS;
        }

        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            $this->error('Dump is empty: '.$path);

            return self::FAILURE;
        }

        if (str_starts_with($sql, "\xFF\xFE") || (strlen($sql) > 1 && $sql[1] === "\0")) {
            $sql = mb_convert_encoding($sql, 'UTF-8', 'UTF-16LE');
            $sql = ltrim($sql, "\xEF\xBB\xBF");
        }

        $this->info(sprintf(
            'Restoring contacts from %s (%s bytes).',
            basename($path),
            number_format((float) filesize($path), 0, '.', ''),
        ));

        $statements = $this->extractExecutableStatements($sql);

        if ($statements === []) {
            $this->error('No executable statements found in dump.');

            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            $executed = 0;

            foreach ($statements as $statement) {
                DB::unprepared($statement);
                $executed++;
            }

            if (Schema::hasTable('legacy_data_imports')) {
                DB::table('legacy_data_imports')->updateOrInsert(
                    ['source' => 'contacts-restore-oct27'],
                    [
                        'imported_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::hasTable($table)) {
                $this->line(sprintf('  %s: %d', $table, DB::table($table)->count()));
            }
        }

        $this->info(sprintf('Restored contacts (%d statements).', $executed));

        return self::SUCCESS;
    }

    private function alreadyRestored(): bool
    {
        if (! Schema::hasTable('legacy_data_imports')) {
            return false;
        }

        return DB::table('legacy_data_imports')
            ->where('source', 'contacts-restore-oct27')
            ->exists();
    }

    /**
     * Quote-aware extraction of DROP / CREATE / INSERT for contact tables only.
     *
     * @return list<string>
     */
    private function extractExecutableStatements(string $sql): array
    {
        $allowed = array_fill_keys([
            'contacts',
            'directory_contacts',
            'directory_contact_stages',
            'directory_contact_comments',
            'directory_contact_persons',
        ], true);

        $statements = [];
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ctype_space($sql[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            if (substr($sql, $i, 2) === '--') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }

            $start = $i;
            $inString = false;
            $escape = false;

            while ($i < $len) {
                $ch = $sql[$i];

                if ($inString) {
                    if ($escape) {
                        $escape = false;
                    } elseif ($ch === '\\') {
                        $escape = true;
                    } elseif ($ch === "'") {
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $i++;
                        } else {
                            $inString = false;
                        }
                    }
                } elseif ($ch === "'") {
                    $inString = true;
                } elseif ($ch === ';') {
                    $statement = trim(substr($sql, $start, $i - $start));
                    $i++;

                    if ($statement !== '' && $this->isAllowedStatement($statement, $allowed)) {
                        $statements[] = $statement;
                    }
                    break;
                }

                $i++;
            }

            if ($i >= $len && $start < $len) {
                break;
            }
        }

        return $statements;
    }

    /**
     * @param  array<string, true>  $allowed
     */
    private function isAllowedStatement(string $statement, array $allowed): bool
    {
        if (preg_match('/^(LOCK|UNLOCK|SET)\b/i', $statement) === 1) {
            return false;
        }

        if (preg_match('/^(DROP TABLE(?: IF EXISTS)?|CREATE TABLE|INSERT INTO)\s+`([^`]+)`/i', $statement, $match) !== 1) {
            return false;
        }

        return isset($allowed[strtolower($match[2])]);
    }
}
