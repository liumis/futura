<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Services\SharepointInvoiceUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharepointInvoiceUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_path_is_invoices_year_and_month(): void
    {
        SharepointSetting::instance()->update([
            'root_folder_path' => '/SS Documents',
        ]);

        $invoice = new Invoice([
            'invoice_date' => '2026-08-03',
        ]);

        $this->assertSame(
            'SS Documents/invoices/2026/2026-08',
            SharepointInvoiceUploader::folderPathForInvoice($invoice),
        );
    }

    public function test_remote_file_name_includes_id_date_and_original(): void
    {
        $invoice = new Invoice([
            'invoice_date' => '2026-08-03',
        ]);
        $invoice->id = 9;

        $this->assertSame(
            '9-2026-08-03-supplier invoice.pdf',
            SharepointInvoiceUploader::remoteFileName($invoice, 'supplier invoice.pdf'),
        );
    }

    public function test_additional_remote_file_name_includes_id_date_and_original(): void
    {
        $invoice = new Invoice([
            'invoice_date' => '2026-08-03',
        ]);
        $invoice->id = 9;

        $this->assertSame(
            '9-2026-08-03-additional-CMR.pdf',
            SharepointInvoiceUploader::additionalRemoteFileName($invoice, 'CMR.pdf'),
        );
    }

    public function test_additional_remote_file_name_adds_suffix_when_already_used(): void
    {
        $invoice = new Invoice([
            'invoice_date' => '2026-08-03',
        ]);
        $invoice->id = 9;

        $this->assertSame(
            '9-2026-08-03-additional-CMR-2.pdf',
            SharepointInvoiceUploader::additionalRemoteFileName($invoice, 'CMR.pdf', [
                ['name' => '9-2026-08-03-additional-CMR.pdf'],
            ]),
        );
    }
}
