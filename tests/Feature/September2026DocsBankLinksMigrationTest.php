<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class September2026DocsBankLinksMigrationTest extends TestCase
{
    public function test_september_docs_migration_is_noop_in_testing(): void
    {
        $path = database_path('migrations/2026_10_28_093000_import_september_2026_docs_and_bank_links.php');
        $this->assertFileExists($path);

        // Migration skips entirely when APP_ENV=testing (set by TestCase).
        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_10_28_093000_import_september_2026_docs_and_bank_links.php',
            '--force' => true,
        ]));
    }
}
