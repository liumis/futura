<?php

namespace Tests\Feature;

use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\FuturaSalesInvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FuturaSalesInvoiceImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_invoices_links_hist_orders_and_bank_payments(): void
    {
        Role::findOrCreate('customer', 'web');

        CompanySetting::instance()->update([
            'company_name' => 'UAB Futura Textiles Europe',
            'company_id' => '306740042',
            'company_address' => 'Ringuvos g. 30-2',
        ]);

        $customer = User::factory()->create([
            'company_name' => 'UAB "Vinilas"',
        ]);
        $customer->assignRole('customer');

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'name' => 'HIST|VINILAS|2025-06-11',
            'order_date' => '2025-06-11',
        ]);

        $path = storage_path('framework/testing/futura-sales-journal.xls');
        @mkdir(dirname($path), 0777, true);
        $this->writeJournal($path, [
            ['VINILAS', 'UAB "Vinilas"', 'FTE0001', '2025-06-11', 765, 160.65, 925.65],
            ['SVETMAR', 'Svetmar d.o.o.', 'FTE0002', '2025-06-12', 100, 0, 100],
        ]);

        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
        ]);
        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->id,
            'fingerprint' => 'txn-1',
            'month_key' => '2025-06',
            'booking_date' => '2025-06-15',
            'direction' => 'credit',
            'amount' => 925.65,
            'currency' => 'EUR',
            'counterpart_name' => 'UAB "Vinilas"',
            'description' => 'Payment FTE0001',
        ]);

        $result = app(FuturaSalesInvoiceImporter::class)->import($path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['linked_orders']);
        $this->assertSame(1, $result['without_order']);
        $this->assertGreaterThanOrEqual(1, $result['bank_linked']);

        $invoice = Invoice::query()->where('invoice_number', 'FTE0001')->first();
        $this->assertNotNull($invoice);
        $this->assertSame((int) $order->id, (int) $invoice->order_id);
        $this->assertEquals(925.65, (float) $invoice->sum_inc_vat);
        $this->assertTrue($invoice->isFullyPaid());
        $this->assertSame('UAB "Vinilas"', $invoice->contact?->company_name);
        $this->assertNotSame(
            CompanySetting::instance()->syncContact()->id,
            (int) $invoice->contact_id,
        );

        $withoutOrder = Invoice::query()->where('invoice_number', 'FTE0002')->first();
        $this->assertNotNull($withoutOrder);
        $this->assertSame('Svetmar d.o.o.', $withoutOrder->contact?->company_name);

        // Idempotent
        $again = app(FuturaSalesInvoiceImporter::class)->import($path, linkBank: false);
        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['skipped_existing']);
    }

    public function test_reimport_fixes_issuer_stored_as_buyer(): void
    {
        Role::findOrCreate('customer', 'web');

        $issuer = CompanySetting::instance();
        $issuer->update([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '306740042',
            'company_address' => 'Ringuvos g. 30-2',
        ]);
        $issuerContact = $issuer->syncContact();

        Invoice::query()->create([
            'invoice_number' => 'FTE0999',
            'invoice_date' => '2025-06-12',
            'contact_id' => $issuerContact->id,
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
            'upload_date' => '2025-06-12',
            'uploaded_by' => User::factory()->create()->id,
        ]);

        $path = storage_path('framework/testing/futura-sales-journal-fix.xls');
        @mkdir(dirname($path), 0777, true);
        $this->writeJournal($path, [
            ['SVETMAR', 'Svetmar d.o.o.', 'FTE0999', '2025-06-12', 100, 0, 100],
        ]);

        app(FuturaSalesInvoiceImporter::class)->import($path, linkBank: false);

        $invoice = Invoice::query()->where('invoice_number', 'FTE0999')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('Svetmar d.o.o.', $invoice->fresh('contact')->contact?->company_name);
        $this->assertNotSame((int) $issuerContact->id, (int) $invoice->contact_id);
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string,4:float,5:float,6:float}>  $rows
     */
    private function writeJournal(string $path, array $rows): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A13', 'Eil. Nr.');
        $sheet->setCellValue('I13', 'Dokumentas');

        $r = 15;
        foreach ($rows as $i => $row) {
            [$code, $name, $doc, $date, $net, $vat, $gross] = $row;
            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('D'.$r, $code);
            $sheet->setCellValue('H'.$r, $name);
            $sheet->setCellValue('I'.$r, $doc);
            $sheet->setCellValue('K'.$r, $date);
            $sheet->setCellValue('M'.$r, $net);
            $sheet->setCellValue('P'.$r, $vat);
            $sheet->setCellValue('S'.$r, $gross);
            $r++;
        }

        (new Xls($spreadsheet))->save($path);
    }
}
