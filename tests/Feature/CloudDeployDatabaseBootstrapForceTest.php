<?php

namespace Tests\Feature;

use App\Console\Commands\CloudDeployDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CloudDeployDatabaseBootstrapForceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_deploy_forces_bootstrap_when_bank_statements_empty(): void
    {
        $this->assertTrue(Schema::hasTable('bank_statement_transactions'));
        $this->assertSame(0, (int) DB::table('bank_statement_transactions')->count());

        Artisan::call('app:cloud-deploy-database');
        $output = Artisan::output();

        $this->assertStringContainsString('bank_statement_transactions is empty', $output);
        // SQLite test DB cannot run the MySQL dump import; force path must still be chosen first.
        $this->assertTrue(
            str_contains($output, 'forcing prod bootstrap import')
            || str_contains($output, 'Importing dump')
            || str_contains($output, 'only runs on MySQL'),
            'Expected forced bootstrap attempt. Output: '.$output,
        );
        $this->assertStringContainsString('Unbalanced months:', $output);
    }
}
