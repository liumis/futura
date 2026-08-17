<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CloudDeployDatabase extends Command
{
    protected $signature = 'app:cloud-deploy-database';

    protected $description = 'Production deploy: run migrations then import the current SQLite snapshot once';

    public function handle(): int
    {
        $migrate = $this->call('migrate', ['--force' => true]);

        if ($migrate !== self::SUCCESS) {
            return $migrate;
        }

        return $this->call('app:import-prod-bootstrap');
    }
}
