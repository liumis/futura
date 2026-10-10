<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BankStatements\BankStatementCounterpartResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementCounterpartResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_remittance_only_labels(): void
    {
        $this->assertTrue(BankStatementCounterpartResolver::isRemittanceOnly('REFUND 2622153237 8MXWT4'));
        $this->assertTrue(BankStatementCounterpartResolver::isRemittanceOnly('GRĄŽINIAMS/REFUND 2622160770 993B3B'));
        $this->assertTrue(BankStatementCounterpartResolver::isRemittanceOnly('pav (refund)'));
        $this->assertTrue(BankStatementCounterpartResolver::isRemittanceOnly('Lėšų grąžinimas (Refund)'));
        $this->assertFalse(BankStatementCounterpartResolver::isRemittanceOnly('Air Baltic Corporation A/S'));
        $this->assertFalse(BankStatementCounterpartResolver::isRemittanceOnly('TENA TEX TRADE , s.r.o.'));
    }

    public function test_extracts_refund_invoice_number(): void
    {
        $this->assertSame(
            '2622153237',
            BankStatementCounterpartResolver::extractRefundInvoiceNumber('REFUND 2622153237 8MXWT4'),
        );
        $this->assertSame(
            '2622160770',
            BankStatementCounterpartResolver::extractRefundInvoiceNumber(
                'FT261838YR17IBFT3511 · GRĄŽINIAMS/REFUND 2622160770 993B3B',
            ),
        );
    }

    public function test_resolves_counterpart_from_invoice_number(): void
    {
        $admin = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Air Baltic Corporation A/S',
            'company_id' => 'AIR-1',
            'company_address' => 'Riga',
        ]);

        Invoice::query()->create([
            'invoice_number' => '2622153237',
            'contact_id' => $contact->getKey(),
            'invoice_date' => '2026-04-23',
            'upload_date' => '2026-04-23',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 617.96,
            'vat' => 0,
            'sum_inc_vat' => 617.96,
            'currency' => 'EUR',
        ]);

        $resolved = BankStatementCounterpartResolver::resolve(
            'REFUND 2622153237 8MXWT4',
            'FT261763L5LJIBFT3503 · REFUND 2622153237 8MXWT4',
        );

        $this->assertSame('Air Baltic Corporation A/S', $resolved);
    }

    public function test_resolves_from_linked_invoice_contact(): void
    {
        $admin = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Air Baltic Corporation A/S',
            'company_id' => 'AIR-2',
            'company_address' => 'Riga',
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => '2622153198',
            'contact_id' => $contact->getKey(),
            'invoice_date' => '2026-04-23',
            'upload_date' => '2026-04-23',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 60.49,
            'vat' => 0,
            'sum_inc_vat' => 60.49,
            'currency' => 'EUR',
        ]);

        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xls',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-18',
            'direction' => BankStatementDirection::Credit,
            'amount' => 60.49,
            'currency' => 'EUR',
            'counterpart_name' => 'REFUND 2622153198 8MWH3K',
            'description' => 'FT26169STCJIBFT3496 · REFUND 2622153198 8MWH3K',
            'invoice_id' => $invoice->getKey(),
        ]);

        $transaction->setRelation('invoice', $invoice->load('contact'));

        $from = $transaction->fromParty();

        $this->assertSame('Air Baltic Corporation A/S', $from['name']);
    }

    public function test_resolves_pav_refund_from_nearby_merchant(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xls',
            'transaction_count' => 2,
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-27',
            'direction' => BankStatementDirection::Debit,
            'amount' => 49.90,
            'currency' => 'EUR',
            'account_iban' => 'LT677044090113979371',
            'counterpart_name' => 'Bolt Operations OU',
            'description' => 'BOLT.EU',
        ]);

        $refund = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-27',
            'direction' => BankStatementDirection::Credit,
            'amount' => 49.90,
            'currency' => 'EUR',
            'account_iban' => 'LT357044090113587970',
            'counterpart_name' => 'pav (refund)',
            'description' => '1782549958019 · pav (refund)',
        ]);

        $resolved = BankStatementCounterpartResolver::resolve(
            $refund->counterpart_name,
            $refund->description,
            $refund->end_to_end_id,
            $refund,
        );

        $this->assertSame('Bolt Operations OU', $resolved);
    }
}
