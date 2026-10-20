<?php

namespace App\Console\Commands;

use App\Services\ProdDatabaseBootstrap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $usersMissing = Schema::hasTable('users') && ! DB::table('users')->exists();

        // Force when env asks, dump is new, prior import never finished, or login DB is empty.
        $force = filter_var(env('PROD_BOOTSTRAP_FORCE', false), FILTER_VALIDATE_BOOLEAN)
            || ! ProdDatabaseBootstrap::alreadyImported()
            || $usersMissing;

        if ($usersMissing) {
            $this->warn('users table is empty — forcing prod bootstrap import.');
        }

        return $this->call('app:import-prod-bootstrap', $force ? ['--force' => true] : []);
    }
}
