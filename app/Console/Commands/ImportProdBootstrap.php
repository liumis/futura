<?php

namespace App\Console\Commands;

use App\Services\ProdDatabaseBootstrap;
use Illuminate\Console\Command;
use RuntimeException;

class ImportProdBootstrap extends Command
{
    protected $signature = 'app:import-prod-bootstrap {--force : Replace existing imported data}';

    protected $description = 'Import the committed SQLite snapshot into MySQL (first production deploy)';

    public function handle(): int
    {
        if (ProdDatabaseBootstrap::alreadyImported() && ! $this->option('force')) {
            $this->info('Legacy SQLite data already imported; skipping.');

            return self::SUCCESS;
        }

        if (! ProdDatabaseBootstrap::hasSnapshot()) {
            $this->warn('No SQLite snapshot found; skipping data import.');

            return self::SUCCESS;
        }

        try {
            $result = ProdDatabaseBootstrap::importToMysql((bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d tables (%d rows) from SQLite snapshot.',
            $result['tables'],
            $result['rows'],
        ));

        return self::SUCCESS;
    }
}
