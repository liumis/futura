<?php

namespace Tests\Unit;

use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\LatePaymentsReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LatePaymentsReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unpaid_invoice_is_late_when_due_date_has_passed(): void
    {
        $this->createInvoice(
            number: '13135/65',
            company: 'FUTURA',
            invoiceDate: '2026-06-26',
            paymentTerm: 30,
            amount: 78091.57,
        );

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-08-31',
            LatePaymentsReportBuilder::SOURCE_ALL,
            LatePaymentsReportBuilder::STATUS_OUTSTANDING,
            '2026-08-26',
        );

        $this->assertSame(1, $report['count']);
        $this->assertSame(1, $report['late_count']);
        $this->assertSame('13135/65', $report['rows'][0]['number']);
        $this->assertSame('FUTURA', $report['rows'][0]['company']);
        $this->assertSame('Invoice', $report['rows'][0]['source_label']);
        $this->assertSame('2026-06-26', $report['rows'][0]['date']);
        $this->assertSame('2026-07-26', $report['rows'][0]['due_date']);
        $this->assertTrue($report['rows'][0]['late']);
        $this->assertSame(78091.57, $report['rows'][0]['amount']);
    }

    public function test_unpaid_invoice_not_yet_due_is_listed_without_late_badge(): void
    {
        $this->createInvoice(
            number: '13188',
            company: 'FORVEDA',
            invoiceDate: '2026-08-01',
            paymentTerm: 31,
            amount: 1200,
        );

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-08-31',
            LatePaymentsReportBuilder::SOURCE_INVOICE,
            LatePaymentsReportBuilder::STATUS_OUTSTANDING,
            '2026-08-26',
        );

        $this->assertSame(1, $report['count']);
        $this->assertSame(0, $report['late_count']);
        $this->assertFalse($report['rows'][0]['late']);
        $this->assertSame('2026-09-01', $report['rows'][0]['due_date']);
    }

    public function test_paid_invoice_and_paid_contract_are_excluded(): void
    {
        $this->createInvoice(
            number: 'PAID-1',
            company: 'Paid Co',
            invoiceDate: '2026-06-01',
            paymentTerm: 14,
            amount: 100,
            paidAmount: 100,
        );
        Payment::factory()->create([
            'name' => 'Paid lease',
            'amount' => 500,
            'schedule' => PaymentSchedule::OneTime,
            'due_date' => '2026-07-01',
            'paid' => true,
        ]);

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-08-31',
            asOf: '2026-08-26',
        );

        $this->assertSame(0, $report['count']);
        $this->assertSame(0.0, $report['grand_total']);
    }

    public function test_partially_paid_invoice_shows_remaining_amount(): void
    {
        $this->createInvoice(
            number: 'PART-1',
            company: 'Partial UAB',
            invoiceDate: '2026-06-01',
            paymentTerm: 10,
            amount: 121,
            paidAmount: 21,
        );

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-08-31',
            LatePaymentsReportBuilder::SOURCE_INVOICE,
            asOf: '2026-08-26',
        );

        $this->assertSame(1, $report['count']);
        $this->assertSame(100.0, $report['rows'][0]['amount']);
        $this->assertSame(100.0, $report['grand_total']);
    }

    public function test_contract_one_time_and_periodical_payments_are_included(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Landlord UAB',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);

        Payment::factory()->create([
            'name' => 'Office lease',
            'contact_id' => $contact->id,
            'amount' => 1200,
            'schedule' => PaymentSchedule::OneTime,
            'due_date' => '2026-07-15',
            'paid' => false,
        ]);
        Payment::factory()->periodical()->create([
            'name' => 'Software subscription',
            'contact_id' => $contact->id,
            'amount' => 49.90,
            'frequency' => PaymentFrequency::Monthly,
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-01',
        ]);

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-08-31',
            LatePaymentsReportBuilder::SOURCE_CONTRACT,
            LatePaymentsReportBuilder::STATUS_OUTSTANDING,
            '2026-08-26',
        );

        $numbers = array_column($report['rows'], 'number');
        $this->assertContains('Office lease', $numbers);
        $this->assertSame(2, $report['count']);
        $this->assertSame(2, $report['late_count']);

        $lease = collect($report['rows'])->firstWhere('number', 'Office lease');
        $this->assertNotNull($lease);
        $this->assertSame('Contract', $lease['source_label']);
        $this->assertSame('Landlord UAB', $lease['company']);
        $this->assertTrue($lease['late']);

        $subscriptionDates = collect($report['rows'])
            ->where('number', 'Software subscription')
            ->pluck('due_date')
            ->values()
            ->all();
        $this->assertSame(['2026-08-01'], $subscriptionDates);
        $this->assertTrue(collect($report['rows'])->where('number', 'Software subscription')->every(fn (array $row): bool => $row['late']));
        $this->assertEqualsWithDelta(1200 + 49.90, $report['grand_total'], 0.01);
    }

    public function test_upcoming_periodical_installment_is_listed_without_late_badge(): void
    {
        Payment::factory()->periodical()->create([
            'name' => 'Hosting',
            'amount' => 20,
            'frequency' => PaymentFrequency::Monthly,
            'start_date' => '2026-08-01',
            'end_date' => '2026-10-01',
        ]);

        $report = LatePaymentsReportBuilder::build(
            '2026-08-01',
            '2026-10-31',
            LatePaymentsReportBuilder::SOURCE_CONTRACT,
            LatePaymentsReportBuilder::STATUS_OUTSTANDING,
            '2026-08-26',
        );

        $dates = collect($report['rows'])->where('number', 'Hosting')->pluck('due_date')->values()->all();
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $dates);
        $this->assertTrue($report['rows'][0]['late']);
        $this->assertFalse($report['rows'][1]['late']);
        $this->assertFalse($report['rows'][2]['late']);
    }

    public function test_late_only_filter_hides_upcoming_payments(): void
    {
        $this->createInvoice(
            number: 'LATE-1',
            company: 'Late Co',
            invoiceDate: '2026-06-01',
            paymentTerm: 14,
            amount: 50,
        );
        $this->createInvoice(
            number: 'SOON-1',
            company: 'Soon Co',
            invoiceDate: '2026-08-20',
            paymentTerm: 30,
            amount: 80,
        );

        $report = LatePaymentsReportBuilder::build(
            '2026-06-01',
            '2026-09-30',
            LatePaymentsReportBuilder::SOURCE_INVOICE,
            LatePaymentsReportBuilder::STATUS_LATE,
            '2026-08-26',
        );

        $this->assertSame(1, $report['count']);
        $this->assertSame('LATE-1', $report['rows'][0]['number']);
        $this->assertTrue($report['rows'][0]['late']);
    }

    private function createInvoice(
        string $number,
        string $company,
        string $invoiceDate,
        int $paymentTerm,
        float $amount,
        float $paidAmount = 0,
    ): Invoice {
        $user = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => $company,
            'company_id' => (string) fake()->unique()->numerify('#########'),
            'company_address' => 'Vilnius',
        ]);

        return Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => $number,
            'invoice_date' => $invoiceDate,
            'upload_date' => $invoiceDate,
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $amount,
            'vat' => 0,
            'sum_inc_vat' => $amount,
            'payment_term' => $paymentTerm,
            'paid_amount' => $paidAmount,
            'paid' => false,
        ]);
    }
}
