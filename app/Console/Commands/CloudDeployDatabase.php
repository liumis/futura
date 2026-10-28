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
        $bankMissing = Schema::hasTable('bank_statement_transactions')
            && ! DB::table('bank_statement_transactions')->exists();
        $invoicesMissing = Schema::hasTable('invoices')
            && ! DB::table('invoices')->exists();

        // Force when env asks, dump is new, prior import never finished, login DB is empty,
        // or finance data is missing (prod can end up migrated but without bootstrap rows).
        $force = filter_var(env('PROD_BOOTSTRAP_FORCE', false), FILTER_VALIDATE_BOOLEAN)
            || ! ProdDatabaseBootstrap::alreadyImported()
            || $usersMissing
            || $bankMissing
            || $invoicesMissing;

        if ($usersMissing) {
            $this->warn('users table is empty — forcing prod bootstrap import.');
        }
        if ($bankMissing) {
            $this->warn('bank_statement_transactions is empty — forcing prod bootstrap import.');
        }
        if ($invoicesMissing) {
            $this->warn('invoices is empty — forcing prod bootstrap import.');
        }

        $bootstrap = $this->call('app:import-prod-bootstrap', $force ? ['--force' => true] : []);

        // Bootstrap reloads the dump after migrate, so content repairs must run again here.
        // Always attempt the repair (even when bootstrap skipped / non-MySQL) — it is idempotent.
        $fix = $this->call('app:fix-unbalanced-finance-months');

        return $bootstrap !== self::SUCCESS ? $bootstrap : $fix;
    }
}
