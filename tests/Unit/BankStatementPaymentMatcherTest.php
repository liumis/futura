<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\BankStatements\BankStatementPaymentMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BankStatementPaymentMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_contract_with_same_amount_contact_and_nearby_due_date(): void
    {
        $contact = $this->makeContact('Office Rent UAB');
        $payment = $this->makePayment($contact, [
            'name' => 'Office rent',
            'amount' => 450,
            'due_date' => '2026-08-01',
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 450,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Rent August',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestion = BankStatementPaymentMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($payment->getKey(), $suggestion->getKey());
    }

    public function test_suggests_when_contract_name_is_in_the_payment_description(): void
    {
        $contact = $this->makeContact('Insurer UAB');
        $payment = $this->makePayment($contact, [
            'name' => 'Civil insurance',
            'amount' => 80,
            'due_date' => '2026-08-02',
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 80,
            'booking_date' => '2026-08-10',
            'counterpart_name' => 'Someone else',
            'description' => 'Payment for Civil insurance',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestion = BankStatementPaymentMatcher::suggestionFor($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame($payment->getKey(), $suggestion->getKey());
    }

    public function test_periodical_contract_can_be_suggested_for_multiple_months(): void
    {
        $contact = $this->makeContact('Landlord UAB');
        $payment = $this->makePayment($contact, [
            'name' => 'Warehouse rent',
            'amount' => 900,
            'schedule' => PaymentSchedule::Periodical,
            'frequency' => PaymentFrequency::Monthly,
            'due_date' => null,
            'start_date' => '2026-07-01',
        ]);
        $july = $this->makeTransaction([
            'amount' => 900,
            'booking_date' => '2026-07-03',
            'month_key' => '2026-07',
            'counterpart_name' => 'Landlord UAB',
            'direction' => BankStatementDirection::Debit,
        ]);
        $august = $this->makeTransaction([
            'amount' => 900,
            'booking_date' => '2026-08-03',
            'month_key' => '2026-08',
            'counterpart_name' => 'Landlord UAB',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestions = BankStatementPaymentMatcher::suggestFor(collect([$july, $august]));

        $this->assertSame($payment->getKey(), $suggestions->get($july->getKey())?->getKey());
        $this->assertSame($payment->getKey(), $suggestions->get($august->getKey())?->getKey());
    }

    public function test_one_time_contract_is_suggested_for_only_one_payment(): void
    {
        $contact = $this->makeContact('Office Rent UAB');
        $payment = $this->makePayment($contact, [
            'name' => 'Office rent',
            'amount' => 20,
            'due_date' => '2026-08-03',
        ]);
        $first = $this->makeTransaction([
            'amount' => 20,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Office rent',
            'direction' => BankStatementDirection::Debit,
        ]);
        $second = $this->makeTransaction([
            'amount' => 20,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Payment',
            'direction' => BankStatementDirection::Debit,
        ]);

        $suggestions = BankStatementPaymentMatcher::suggestFor(collect([$first, $second]));

        $this->assertCount(1, $suggestions);
        $this->assertSame($payment->getKey(), $suggestions->get($first->getKey())?->getKey());
        $this->assertNull($suggestions->get($second->getKey()));
    }

    public function test_link_sets_payment_id_marks_one_time_paid_and_rejects_invoice_link(): void
    {
        $contact = $this->makeContact('Office Rent UAB');
        $payment = $this->makePayment($contact, ['amount' => 10, 'due_date' => '2026-08-03']);
        $transaction = $this->makeTransaction(['amount' => 10, 'direction' => BankStatementDirection::Debit]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-X',
            'invoice_date' => '2026-08-03',
            'upload_date' => '2026-08-24',
            'uploaded_by' => User::factory()->create()->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        BankStatementPaymentMatcher::link($transaction, $payment);

        $transaction = $transaction->fresh();
        $this->assertSame($payment->getKey(), $transaction->payment_id);
        $this->assertNull($transaction->invoice_id);
        $this->assertTrue((bool) $payment->fresh()->paid);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already linked to a contract');

        BankStatementInvoiceMatcher::link($transaction, $invoice);
    }

    public function test_unlink_clears_payment_and_unmarks_one_time_paid(): void
    {
        $contact = $this->makeContact('Office Rent UAB');
        $payment = $this->makePayment($contact, ['amount' => 10, 'due_date' => '2026-08-03']);
        $transaction = $this->makeTransaction(['amount' => 10]);

        BankStatementPaymentMatcher::link($transaction, $payment);
        BankStatementPaymentMatcher::unlink($transaction->fresh());

        $this->assertNull($transaction->fresh()->payment_id);
        $this->assertFalse((bool) $payment->fresh()->paid);
    }

    public function test_does_not_suggest_when_already_linked_to_invoice(): void
    {
        $contact = $this->makeContact('Office Rent UAB');
        $this->makePayment($contact, ['amount' => 10, 'due_date' => '2026-08-03']);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-Y',
            'invoice_date' => '2026-08-03',
            'upload_date' => '2026-08-24',
            'uploaded_by' => User::factory()->create()->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);
        $transaction = $this->makeTransaction([
            'amount' => 10,
            'counterpart_name' => 'Office Rent UAB',
            'invoice_id' => $invoice->getKey(),
        ]);

        $this->assertNull(BankStatementPaymentMatcher::suggestionFor($transaction));
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
    private function makePayment(Contact $contact, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'contact_id' => $contact->getKey(),
            'name' => 'Office rent',
            'amount' => 10,
            'schedule' => PaymentSchedule::OneTime,
            'due_date' => '2026-08-03',
            'paid' => false,
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
            'direction' => BankStatementDirection::Debit,
            'amount' => 10,
            'currency' => 'EUR',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Payment',
        ], $overrides));
    }
}
