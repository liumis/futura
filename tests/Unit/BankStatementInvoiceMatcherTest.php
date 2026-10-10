<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BankStatementInvoiceMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_invoice_with_same_amount_contact_and_nearby_date(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-1001',
            'invoice_date' => '2026-08-01',
            'sum_inc_vat' => 150.50,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 150.50,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_numberless_invoice_when_contact_amount_and_date_match(): void
    {
        $contact = $this->makeContact('Go 360 Group Jakub Wittbrodt');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => null,
            'invoice_date' => '2026-06-08',
            'sum_inc_vat' => 6202.80,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 6202.80,
            'booking_date' => '2026-06-02',
            'counterpart_name' => 'Go 360 Group Jakub Wittbrodt 1st payment 80%',
            'description' => 'CLR123',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_when_face_value_matches_foreign_currency_invoice(): void
    {
        $contact = $this->makeContact('Go 360 Group Jakub Wittbrodt');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => null,
            'invoice_date' => '2026-06-08',
            'sum_inc_vat' => 6202.80,
            'currency' => 'PLN',
            'sum_inc_vat_eur' => 1439.90,
            'currency_rate' => 4.308,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 6202.80,
            'booking_date' => '2026-06-02',
            'counterpart_name' => 'Go 360 Group Jakub Wittbrodt 1st payment 80%',
            'description' => 'VM316',
            'direction' => BankStatementDirection::Debit,
            'currency' => 'EUR',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_prefers_closer_invoice_when_two_share_contact_and_amount(): void
    {
        $contact = $this->makeContact('Supplier UAB');
        $far = $this->makeInvoice($contact, [
            'invoice_number' => 'FAR-1',
            'invoice_date' => '2026-07-01',
            'sum_inc_vat' => 100,
        ]);
        $near = $this->makeInvoice($contact, [
            'invoice_number' => 'NEAR-1',
            'invoice_date' => '2026-08-02',
            'sum_inc_vat' => 100,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 100,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Supplier UAB',
            'description' => 'Payment',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($near->getKey(), $suggestion->getKey());
        $this->assertNotSame($far->getKey(), $suggestion->getKey());
    }

    public function test_suggests_when_invoice_number_is_in_the_payment_description(): void
    {
        $contact = $this->makeContact('Other Co');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'FT-8891',
            'invoice_date' => '2026-08-02',
            'sum_inc_vat' => 80,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 80,
            'booking_date' => '2026-08-10',
            'counterpart_name' => 'Someone else',
            'description' => 'Payment for FT-8891',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_does_not_suggest_when_two_invoices_are_equally_likely(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $this->makeInvoice($contact, [
            'invoice_number' => 'A-1',
            'invoice_date' => '2026-08-03',
            'sum_inc_vat' => 50,
        ]);
        $this->makeInvoice($contact, [
            'invoice_number' => 'A-2',
            'invoice_date' => '2026-08-03',
            'sum_inc_vat' => 50,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 50,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
        ]);

        $this->assertNull(BankStatementInvoiceMatcher::suggestionFor($transaction));
    }

    public function test_does_not_suggest_different_amount_without_invoice_number(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $this->makeInvoice($contact, [
            'invoice_number' => 'INV-9',
            'invoice_date' => '2026-08-03',
            'sum_inc_vat' => 99,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 10,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ]);

        $this->assertNull(BankStatementInvoiceMatcher::suggestionFor($transaction));
    }

    public function test_each_invoice_is_suggested_for_only_one_payment(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-1001',
            'invoice_date' => '2026-08-03',
            'sum_inc_vat' => 20,
        ]);
        $first = $this->makeTransaction([
            'amount' => 20,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001',
        ]);
        $second = $this->makeTransaction([
            'amount' => 20,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ]);

        $suggestions = BankStatementInvoiceMatcher::suggestFor(collect([$first, $second]));

        $this->assertCount(1, $suggestions);
        $this->assertSame($invoice->getKey(), $suggestions->get($first->getKey())?->getKey());
        $this->assertNull($suggestions->get($second->getKey()));
    }

    public function test_link_sets_invoice_id_and_rejects_a_second_link(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $invoice = $this->makeInvoice($contact, ['sum_inc_vat' => 10]);
        $transaction = $this->makeTransaction(['amount' => 10]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $this->assertSame($invoice->getKey(), $transaction->fresh()->invoice_id);
        $this->assertTrue($invoice->fresh()->isFullyPaid());
        $this->assertSame(10.0, (float) $invoice->fresh()->paid_amount);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already linked');

        BankStatementInvoiceMatcher::link($transaction->fresh(), $invoice);
    }

    public function test_link_can_partly_pay_an_invoice_and_unlink_reverses_it(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-PARTIAL',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 40,
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-PARTIAL',
        ]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $invoice->refresh();
        $this->assertSame('partly_paid', $invoice->paymentSettlementStatus());
        $this->assertSame(40.0, (float) $invoice->paid_amount);
        $this->assertSame(60.0, $invoice->outstandingAmount());

        $summary = $transaction->fresh()->load('invoice')->settlementSummary();
        // Income (credit) reflects the invoice settlement.
        $this->assertSame('partly_paid', $summary['status']);
        $this->assertSame('Partly paid', $summary['label']);
        $this->assertStringContainsString('paid', (string) $summary['detail']);
        $this->assertStringContainsString('left', (string) $summary['detail']);

        BankStatementInvoiceMatcher::unlink($transaction->fresh());

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->paymentSettlementStatus());
        $this->assertSame(0.0, (float) $invoice->paid_amount);
    }

    public function test_outgoing_partial_cover_of_larger_invoice_shows_sum_mismatch(): void
    {
        $contact = $this->makeContact('Supplier UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-OUT',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'counterpart_name' => 'Supplier UAB',
            'description' => 'INV-OUT',
        ]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $invoice->refresh();
        $this->assertSame('partly_paid', $invoice->paymentSettlementStatus());

        $summary = $transaction->fresh()->load(['invoice', 'invoices'])->settlementSummary();
        $this->assertSame('underpaid', $summary['status']);
        $this->assertSame('Sum mismatch', $summary['label']);
        $this->assertStringContainsString('short', (string) $summary['detail']);
    }

    public function test_outgoing_linked_invoices_sum_mismatch_is_visible(): void
    {
        $contact = $this->makeContact('DSV ROAD UAB');
        $first = $this->makeInvoice($contact, [
            'invoice_number' => '1008464',
            'sum_inc_vat' => 1000,
            'sum_without_vat' => 1000,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $second = $this->makeInvoice($contact, [
            'invoice_number' => '1008463',
            'sum_inc_vat' => 800,
            'sum_without_vat' => 800,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'amount' => 1500,
            'counterpart_name' => 'DSV ROAD UAB',
            'description' => '1008464, 1008463',
        ]);

        BankStatementInvoiceMatcher::linkMany($transaction, collect([$first, $second]));

        $summary = $transaction->fresh()->load('invoices')->settlementSummary();
        $this->assertSame('underpaid', $summary['status']);
        $this->assertSame('Sum mismatch', $summary['label']);
        $this->assertStringContainsString('short', (string) $summary['detail']);
        $this->assertStringContainsString('2 invoices', (string) $summary['detail']);
    }

    public function test_outgoing_linked_invoices_overpayment_is_visible(): void
    {
        $contact = $this->makeContact('Supplier Over UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-OVER',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'amount' => 150,
            'counterpart_name' => 'Supplier Over UAB',
            'description' => 'INV-OVER',
        ]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $summary = $transaction->fresh()->load(['invoice', 'invoices'])->settlementSummary();
        $this->assertSame('overpaid', $summary['status']);
        $this->assertSame('Overpayment', $summary['label']);
        $this->assertStringContainsString('over', (string) $summary['detail']);
    }

    public function test_overpayment_refunded_flag_marks_settled(): void
    {
        $contact = $this->makeContact('Customer Over UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-OVER-R',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 100,
            'paid' => true,
        ]);
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'amount' => 150,
            'counterpart_name' => 'Customer Over UAB',
            'description' => 'INV-OVER-R',
        ]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $transaction = $transaction->fresh()->load(['invoice', 'invoices']);
        $this->assertSame('overpaid', $transaction->settlementSummary()['status']);

        $transaction->update(['overpayment_refunded' => true]);

        $summary = $transaction->fresh()->load(['invoice', 'invoices'])->settlementSummary();
        $this->assertSame('settled', $summary['status']);
        $this->assertSame('Settled', $summary['label']);
        $this->assertStringContainsString('Overpayment refunded', (string) $summary['detail']);
        $this->assertSame(50.0, $summary['overpayment_amount']);
    }

    public function test_link_marks_invoice_paid_when_sum_is_covered(): void
    {
        $contact = $this->makeContact('Supplier UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-FULL',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'amount' => 100,
            'counterpart_name' => 'Supplier UAB',
            'description' => 'INV-FULL',
        ]);

        BankStatementInvoiceMatcher::link($transaction, $invoice);

        $invoice->refresh();
        $this->assertTrue((bool) $invoice->paid);
        $this->assertSame('paid', $invoice->paymentSettlementStatus());
        $this->assertSame(100.0, (float) $invoice->paid_amount);
    }

    public function test_second_payment_can_be_suggested_against_outstanding_balance(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => 'INV-1001',
            'invoice_date' => '2026-08-03',
            'sum_inc_vat' => 100,
            'sum_without_vat' => 100,
            'paid_amount' => 40,
            'paid' => false,
        ]);
        $this->makeTransaction([
            'amount' => 40,
            'booking_date' => '2026-08-01',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001 first',
            'invoice_id' => $invoice->getKey(),
        ]);
        $second = $this->makeTransaction([
            'amount' => 60,
            'booking_date' => '2026-08-05',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001 rest',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($second);

        $this->assertSame($invoice->getKey(), $suggestion?->getKey());
    }

    public function test_suggests_using_order_customer_when_invoice_contact_is_issuer(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create([
            'company_name' => 'UAB "Vinilas"',
            'name' => 'Vinilas',
        ]);
        $order = Order::factory()->create([
            'user_id' => $customer->id,
        ]);
        $invoice = $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0092',
            'invoice_date' => '2026-05-01',
            'sum_inc_vat' => 2452.80,
            'sum_without_vat' => 2452.80,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 2452.80,
            'booking_date' => '2026-05-15',
            'counterpart_name' => 'UAB "Vinilas"',
            'description' => 'Payment for goods after cover FTE0092',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_when_abbreviated_invoice_number_is_in_comment(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create(['company_name' => 'Svetmar d.o.o.']);
        $order = Order::factory()->create(['user_id' => $customer->id]);
        $invoice = $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0095',
            'invoice_date' => '2026-06-20',
            'sum_inc_vat' => 8224,
            'sum_without_vat' => 8224,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 8224,
            'booking_date' => '2026-06-30',
            'counterpart_name' => 'Svetmar d.o.o.',
            'description' => 'Svetmar d.o.o. INVOICE NR.:0095',
            'comment' => '',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_from_serija_fte_nr_in_description(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create(['company_name' => 'Ariel italia Srls']);
        $order = Order::factory()->create(['user_id' => $customer->id]);
        $invoice = $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0112',
            'invoice_date' => '2026-06-15',
            'sum_inc_vat' => 1950,
            'sum_without_vat' => 1950,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 1950,
            'booking_date' => '2026-06-25',
            'counterpart_name' => 'Ariel italia Srls',
            'description' => '1101261960186246 · SERIJA FTE NR.: 0112',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_from_rif_abbreviation_in_description(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create(['company_name' => 'ABITEX SRL']);
        $order = Order::factory()->create(['user_id' => $customer->id]);
        $invoice = $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0115',
            'invoice_date' => '2026-06-18',
            'sum_inc_vat' => 974,
            'sum_without_vat' => 974,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 974,
            'booking_date' => '2026-07-30',
            'counterpart_name' => 'ABITEX SRL',
            'description' => '0126072850983628 · Rif.:0115',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_does_not_suggest_other_invoice_when_comment_names_a_different_number(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create(['company_name' => 'Svetmar d.o.o.']);
        $order = Order::factory()->create(['user_id' => $customer->id]);

        // Already-settled invoice named in the remittance.
        $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0083',
            'invoice_date' => '2026-04-15',
            'sum_inc_vat' => 207,
            'sum_without_vat' => 207,
            'paid_amount' => 207,
            'paid' => true,
        ]);

        // Unrelated partly-paid invoice whose outstanding happens to equal this payment.
        $otherOrder = Order::factory()->create(['user_id' => $customer->id]);
        $this->makeInvoice($issuer, [
            'order_id' => $otherOrder->id,
            'invoice_number' => 'FTE0095',
            'invoice_date' => '2026-06-20',
            'sum_inc_vat' => 8431,
            'sum_without_vat' => 8431,
            'paid_amount' => 8224,
            'paid' => false,
        ]);

        $transaction = $this->makeTransaction([
            'amount' => 207,
            'booking_date' => '2026-06-08',
            'counterpart_name' => 'Svetmar d.o.o.',
            'description' => '2626152000/00001 · INVOICE NR.:0083',
        ]);

        $this->assertNull(BankStatementInvoiceMatcher::suggestionFor($transaction));
    }

    public function test_ignores_transfer_note_invoice_numbers(): void
    {
        $contact = $this->makeContact('Customer UAB');
        $this->makeInvoice($contact, [
            'invoice_number' => 'Tarp savo sąskaitų (Between account)',
            'invoice_date' => '2026-07-30',
            'sum_inc_vat' => 974,
            'sum_without_vat' => 974,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 974,
            'booking_date' => '2026-07-30',
            'counterpart_name' => 'ABITEX SRL',
            'description' => '0126072850983628 · Rif.:0115',
        ]);

        $this->assertNull(BankStatementInvoiceMatcher::suggestionFor($transaction));
    }

    public function test_suggests_customer_and_amount_from_description_without_invoice_number(): void
    {
        $issuer = $this->makeContact('Futura Textiles Europe, UAB');
        $customer = User::factory()->create(['company_name' => 'Autoleder KFT']);
        $order = Order::factory()->create(['user_id' => $customer->id]);
        $invoice = $this->makeInvoice($issuer, [
            'order_id' => $order->id,
            'invoice_number' => 'FTE0080',
            'invoice_date' => '2026-03-20',
            'sum_inc_vat' => 15732,
            'sum_without_vat' => 15732,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 15732,
            'booking_date' => '2026-04-08',
            'counterpart_name' => 'Autoleder KFT atsiskaitymas',
            'description' => 'Autoleder KFT atsiskaitymas',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    public function test_suggests_and_links_exact_remittance_invoice_batch(): void
    {
        $contact = $this->makeContact('DSV ROAD UAB');
        $first = $this->makeInvoice($contact, [
            'invoice_number' => '1016335',
            'invoice_date' => '2026-07-29',
            'sum_inc_vat' => 113.69,
            'sum_without_vat' => 113.69,
        ]);
        $second = $this->makeInvoice($contact, [
            'invoice_number' => '1016571',
            'invoice_date' => '2026-07-30',
            'sum_inc_vat' => 329.00,
            'sum_without_vat' => 329.00,
        ]);
        $third = $this->makeInvoice($contact, [
            'invoice_number' => '1016681',
            'invoice_date' => '2026-07-31',
            'sum_inc_vat' => 190.58,
            'sum_without_vat' => 190.58,
        ]);
        $fourth = $this->makeInvoice($contact, [
            'invoice_number' => '1017154',
            'invoice_date' => '2026-07-31',
            'sum_inc_vat' => 1936.67,
            'sum_without_vat' => 1936.67,
        ]);

        $transaction = $this->makeTransaction([
            'amount' => 2569.94,
            'booking_date' => '2026-08-06',
            'direction' => BankStatementDirection::Debit,
            'counterpart_name' => 'DSV ROAD UAB',
            'description' => '1016335,1016571,1016681,1017154',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);
        $this->assertNotNull($suggestion);
        $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);
        $this->assertCount(4, $batch);
        $this->assertSame(
            [$fourth->getKey(), $second->getKey(), $third->getKey(), $first->getKey()],
            $batch->modelKeys(),
        );

        BankStatementInvoiceMatcher::link($transaction, $suggestion);
        $transaction->refresh();

        $this->assertSame($fourth->getKey(), $transaction->invoice_id);
        $this->assertCount(4, $transaction->invoices);
        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
        $this->assertTrue($third->fresh()->isFullyPaid());
        $this->assertTrue($fourth->fresh()->isFullyPaid());

        BankStatementInvoiceMatcher::unlink($transaction->fresh());
        $this->assertNull($transaction->fresh()->invoice_id);
        $this->assertSame(0, $transaction->fresh()->invoices()->count());
        $this->assertFalse($first->fresh()->isFullyPaid());
        $this->assertSame(0.0, round((float) $first->fresh()->paid_amount, 2));
    }

    public function test_batch_ignores_counterpart_and_bank_ref_digit_noise(): void
    {
        $contact = $this->makeContact('DSV ROAD UAB');
        $a = $this->makeInvoice($contact, [
            'invoice_number' => '1018023',
            'invoice_date' => '2026-08-04',
            'sum_inc_vat' => 193.91,
            'sum_without_vat' => 193.91,
        ]);
        $b = $this->makeInvoice($contact, [
            'invoice_number' => '1018315',
            'invoice_date' => '2026-08-05',
            'sum_inc_vat' => 13.44,
            'sum_without_vat' => 13.44,
        ]);

        $transaction = $this->makeTransaction([
            'amount' => 207.35,
            'booking_date' => '2026-08-17',
            'direction' => BankStatementDirection::Debit,
            'counterpart_name' => 'DSV ROAD UAB F12316603001244048',
            'description' => '1018023,1018315',
            'end_to_end_id' => 'RMO27462855',
            'account_servicer_ref' => 'RMO27462855',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);
        $this->assertNotNull($suggestion);
        $this->assertCount(2, BankStatementInvoiceMatcher::batchInvoicesFor($transaction));

        BankStatementInvoiceMatcher::link($transaction, $suggestion);
        $this->assertTrue($a->fresh()->isFullyPaid());
        $this->assertTrue($b->fresh()->isFullyPaid());
    }

    public function test_suggests_unique_amount_sum_batch_without_invoice_numbers(): void
    {
        $contact = $this->makeContact('PEDRO TAVARES - SOLUCOES TEXTEIS');
        $first = $this->makeInvoice($contact, [
            'invoice_number' => 'FTE0122',
            'invoice_date' => '2026-08-01',
            'sum_inc_vat' => 1000.00,
            'sum_without_vat' => 1000.00,
        ]);
        $second = $this->makeInvoice($contact, [
            'invoice_number' => 'FTE0132',
            'invoice_date' => '2026-08-10',
            'sum_inc_vat' => 1275.00,
            'sum_without_vat' => 1275.00,
        ]);
        // Distractor that must not create a second valid combo.
        $this->makeInvoice($contact, [
            'invoice_number' => 'FTE0199',
            'invoice_date' => '2026-08-12',
            'sum_inc_vat' => 50.00,
            'sum_without_vat' => 50.00,
        ]);

        $transaction = $this->makeTransaction([
            'amount' => 2275.00,
            'booking_date' => '2026-08-19',
            'direction' => BankStatementDirection::Credit,
            'counterpart_name' => 'PEDRO TAVARES - SOLUCOES TEXTEIS UNIPESSOAL LDA',
            // IBAN / bank refs must not block amount-sum batching.
            'description' => 'PT50001800033135339402091 TOTAPTPL6901ITE01547518',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);
        $this->assertNotNull($suggestion);
        $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);
        $this->assertCount(2, $batch);
        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            $batch->modelKeys(),
        );

        BankStatementInvoiceMatcher::link($transaction, $suggestion);
        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
        $this->assertFalse(
            Invoice::query()->where('invoice_number', 'FTE0199')->firstOrFail()->isFullyPaid(),
        );
    }

    public function test_remittance_batch_links_orphan_paid_invoices(): void
    {
        $contact = $this->makeContact('DSV ROAD UAB');
        $first = $this->makeInvoice($contact, [
            'invoice_number' => '1010885',
            'invoice_date' => '2026-07-10',
            'sum_inc_vat' => 166.59,
            'sum_without_vat' => 166.59,
            'paid_amount' => 166.59,
        ]);
        $second = $this->makeInvoice($contact, [
            'invoice_number' => '1010918',
            'invoice_date' => '2026-07-11',
            'sum_inc_vat' => 133.10,
            'sum_without_vat' => 133.10,
            'paid_amount' => 133.10,
        ]);
        $third = $this->makeInvoice($contact, [
            'invoice_number' => '1011087',
            'invoice_date' => '2026-07-12',
            'sum_inc_vat' => 309.55,
            'sum_without_vat' => 309.55,
            'paid_amount' => 309.55,
        ]);

        $this->assertTrue($first->isFullyPaid());
        $this->assertFalse($first->hasBankPaymentLinks());

        $transaction = $this->makeTransaction([
            'amount' => 609.24,
            'booking_date' => '2026-07-23',
            'direction' => BankStatementDirection::Debit,
            'counterpart_name' => 'DSV ROAD UAB',
            'description' => '1010885,1010918,1011087',
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);
        $this->assertNotNull($suggestion);
        $this->assertCount(3, BankStatementInvoiceMatcher::batchInvoicesFor($transaction));

        BankStatementInvoiceMatcher::link($transaction, $suggestion);
        $transaction->refresh();

        $this->assertCount(3, $transaction->invoices);
        $this->assertTrue($first->fresh()->hasBankPaymentLinks());
        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
        $this->assertTrue($third->fresh()->isFullyPaid());
    }

    public function test_search_invoices_matches_by_amount(): void
    {
        $contact = $this->makeContact('Amount Search Co');
        $match = $this->makeInvoice($contact, [
            'invoice_number' => 'AMT-100',
            'invoice_date' => '2026-08-01',
            'sum_inc_vat' => 73.29,
        ]);
        $this->makeInvoice($contact, [
            'invoice_number' => 'AMT-101',
            'invoice_date' => '2026-08-02',
            'sum_inc_vat' => 18.00,
        ]);

        $byDot = BankStatementInvoiceMatcher::searchInvoices('73.29');
        $this->assertArrayHasKey($match->getKey(), $byDot);

        $byComma = BankStatementInvoiceMatcher::searchInvoices('73,29');
        $this->assertArrayHasKey($match->getKey(), $byComma);

        $byEuro = BankStatementInvoiceMatcher::searchInvoices('€73.29');
        $this->assertArrayHasKey($match->getKey(), $byEuro);
    }

    public function test_suggests_numberless_invoice_despite_unrelated_remittance_token(): void
    {
        $contact = $this->makeContact('IT Specai, UAB');
        $invoice = $this->makeInvoice($contact, [
            'invoice_number' => null,
            'invoice_date' => '2026-07-08',
            'sum_inc_vat' => 37.51,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 37.51,
            'booking_date' => '2026-07-16',
            'counterpart_name' => 'IT Specai, UAB',
            'description' => 'ITC002322',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestion = BankStatementInvoiceMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($invoice->getKey(), $suggestion->getKey());
    }

    private function makeContact(string $name): Contact
    {
        return Contact::query()->create([
            'company_name' => $name,
            'company_id' => 'C-'.uniqid(),
            'company_address' => 'Vilnius',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInvoice(Contact $contact, array $overrides = []): Invoice
    {
        $user = User::factory()->create();

        return Invoice::query()->create(array_merge([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-'.uniqid(),
            'invoice_date' => '2026-08-03',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $overrides['sum_inc_vat'] ?? 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 10,
            'currency' => 'EUR',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ], $overrides));
    }
}
