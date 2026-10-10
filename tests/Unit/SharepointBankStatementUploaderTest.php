<?php

namespace Tests\Unit;

use App\Models\BankStatementImport;
use App\Models\SharepointSetting;
use App\Services\SharepointBankStatementUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharepointBankStatementUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_path_is_bank_statements_year_and_month(): void
    {
        SharepointSetting::instance()->update([
            'root_folder_path' => '/SS Documents',
        ]);

        $import = new BankStatementImport([
            'period_from' => '2026-08-03',
            'original_filename' => 'swedbank.xml',
        ]);

        $this->assertSame(
            'SS Documents/bank-statements/2026/2026-08',
            SharepointBankStatementUploader::folderPathForImport($import),
        );
    }

    public function test_remote_file_name_includes_id_date_and_original(): void
    {
        $import = new BankStatementImport([
            'period_from' => '2026-08-03',
            'original_filename' => 'Swedbank statement.xml',
        ]);
        $import->id = 12;

        $this->assertSame(
            '12-2026-08-03-Swedbank statement.xml',
            SharepointBankStatementUploader::remoteFileName($import),
        );
    }
}
