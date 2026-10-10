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

        return $this->call('app:import-prod-bootstrap', $force ? ['--force' => true] : []);
    }
}
