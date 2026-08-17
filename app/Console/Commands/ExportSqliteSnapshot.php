<?php

namespace App\Console\Commands;

use App\Services\ProdDatabaseBootstrap;
use Illuminate\Console\Command;

class ExportSqliteSnapshot extends Command
{
    protected $signature = 'app:export-sqlite-snapshot {--path= : Optional SQLite file path}';

    protected $description = 'Export the current SQLite database into database/snapshots/prod-bootstrap.json.gz for MySQL first deploy';

    public function handle(): int
    {
        $result = ProdDatabaseBootstrap::exportFromSqlite(
            filled($this->option('path')) ? (string) $this->option('path') : null
        );

        $this->info(sprintf(
            'Snapshot written (%d tables, %d rows): %s',
            $result['tables'],
            $result['rows'],
            $result['path'],
        ));

        return self::SUCCESS;
    }
}
