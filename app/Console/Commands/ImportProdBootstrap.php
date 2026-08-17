<?php

namespace App\Console\Commands;

use App\Services\ProdDatabaseBootstrap;
use Illuminate\Console\Command;
use RuntimeException;

class ImportProdBootstrap extends Command
{
    protected $signature = 'app:import-prod-bootstrap {--force : Replace existing imported data}';

    protected $description = 'Import the committed MySQL dump into production (first deploy, or when the dump changes)';

    public function handle(): int
    {
        if (ProdDatabaseBootstrap::alreadyImported() && ! $this->option('force')) {
            $this->info('This MySQL dump was already imported; skipping.');

            return self::SUCCESS;
        }

        if (! ProdDatabaseBootstrap::hasSnapshot()) {
            $this->warn('No MySQL dump found; skipping data import.');

            return self::SUCCESS;
        }

        try {
            $result = ProdDatabaseBootstrap::importToMysql((bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported MySQL dump (%d statements).',
            $result['tables'],
        ));

        return self::SUCCESS;
    }
}
