<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceEuroConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_eur_invoice_stores_rate_one_and_same_total(): void
    {
        $invoice = $this->makeInvoice([
            'currency' => 'EUR',
            'sum_inc_vat' => 120.50,
        ]);

        $this->assertSame(1.0, (float) $invoice->currency_rate);
        $this->assertSame(120.50, (float) $invoice->sum_inc_vat_eur);
        $this->assertSame(120.50, $invoice->amountInEur());
    }

    public function test_usd_invoice_converts_using_currency_list_rate(): void
    {
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'rate' => 1.10,
            'rate_date' => '2026-09-21',
        ]);

        $invoice = $this->makeInvoice([
            'currency' => 'USD',
            'sum_inc_vat' => 110.00,
            'sum_without_vat' => 110.00,
        ]);

        $this->assertSame(1.10, (float) $invoice->currency_rate);
        $this->assertSame(100.00, (float) $invoice->sum_inc_vat_eur);
        $this->assertSame(100.00, $invoice->amountInEur());
        $this->assertSame(100.00, $invoice->comparableAmountForBank('EUR'));
        $this->assertSame(110.00, $invoice->amountFromBankPayment(100.00, 'EUR'));
    }

    public function test_updating_amount_refreshes_eur_total_with_current_rate(): void
    {
        Currency::query()->create([
            'code' => 'GBP',
            'name' => 'Pound Sterling',
            'rate' => 0.80,
            'rate_date' => '2026-09-21',
        ]);

        $invoice = $this->makeInvoice([
            'currency' => 'GBP',
            'sum_inc_vat' => 80.00,
            'sum_without_vat' => 80.00,
        ]);
        $this->assertSame(100.00, (float) $invoice->sum_inc_vat_eur);

        Currency::query()->where('code', 'GBP')->update(['rate' => 0.85]);

        $invoice->sum_inc_vat = 85.00;
        $invoice->save();
        $invoice->refresh();

        $this->assertSame(0.85, (float) $invoice->currency_rate);
        $this->assertSame(100.00, (float) $invoice->sum_inc_vat_eur);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInvoice(array $overrides = []): Invoice
    {
        $user = User::factory()->create();
        $contact = \App\Models\Contact::query()->create([
            'company_name' => 'FX Test Co',
            'company_id' => 'FX-'.uniqid(),
            'company_address' => 'Vilnius',
        ]);

        return Invoice::query()->create(array_merge([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FX-'.uniqid(),
            'invoice_date' => '2026-09-01',
            'upload_date' => '2026-09-21',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'currency' => 'EUR',
        ], $overrides));
    }
}
