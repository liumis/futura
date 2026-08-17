<?php

namespace App\Console\Commands;

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

        $force = filter_var(env('PROD_BOOTSTRAP_FORCE', false), FILTER_VALIDATE_BOOLEAN);

        return $this->call('app:import-prod-bootstrap', $force ? ['--force' => true] : []);
    }
}
