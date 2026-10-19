<?php

namespace App\Console\Commands;

use App\Services\ProdDatabaseBootstrap;
use Illuminate\Console\Command;

class CloudDeployDatabase extends Command
{
    protected $signature = 'app:cloud-deploy-database';

    protected $description = 'Production deploy: run migrations then import the MySQL dump if it is new';

    public function handle(): int
    {
        $migrate = $this->call('migrate', ['--force' => true]);

        if ($migrate !== self::SUCCESS) {
            return $migrate;
        }

        // Force when env asks, or when the dump checksum is new / prior import never finished.
        $force = filter_var(env('PROD_BOOTSTRAP_FORCE', false), FILTER_VALIDATE_BOOLEAN)
            || ! ProdDatabaseBootstrap::alreadyImported();

        $import = $this->call('app:import-prod-bootstrap', $force ? ['--force' => true] : []);

        if ($import === self::SUCCESS) {
            return self::SUCCESS;
        }

        // Do not block release activation: schema/theme must ship even if bootstrap
        // import needs a follow-up. Re-run with PROD_BOOTSTRAP_FORCE=true after fix.
        $this->error('Bootstrap import failed; continuing deploy so the release can activate.');

        return self::SUCCESS;
    }
}
