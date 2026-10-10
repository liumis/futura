<?php

namespace Tests\Unit;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\User;
use App\Services\ExpenseTypeAccountMapper;
use App\Services\InvoiceDefaultFinanceLines;
use App\Services\LedgerRecalculator;
use App\Services\PayrollLedgerPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerMvpTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'ledger-mvp@example.com']);
        $this->actingAs($this->user);

        foreach (['2410', '2441', '4430', '4492', '5000', '5001', '2040', '6000', '6304', '4480', '4481', '4482', '6401', '6208'] as $code) {
            InvoiceCode::query()->create(['code' => $code, 'name' => $code]);
        }

        CompanySetting::instance()->update([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '306740042',
            'company_vat' => 'LT100016500314',
            'company_address' => 'Vilnius',
            'company_email' => 'info@example.com',
            'company_phone' => '+370',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInvoice(array $overrides): Invoice
    {
        return Invoice::query()->create(array_merge([
            'upload_date' => '2026-05-10',
            'uploaded_by' => $this->user->id,
            'pdf_path' => '',
            'currency' => 'EUR',
            'currency_rate' => 1,
            'paid' => false,
            'paid_amount' => 0,
            'payment_term' => 0,
        ], $overrides));
    }

    private function makeContact(string $name, string $companyId): Contact
    {
        return Contact::query()->create([
            'company_name' => $name,
            'company_id' => $companyId,
            'company_address' => 'Vilnius',
        ]);
    }

    public function test_purchase_lines_use_expense_account_vat_input_and_suppliers(): void
    {
        $expense = ExpenseType::query()->create(['name' => 'Transportas']);
        ExpenseTypeAccountMapper::syncDefaults();
        $expense->refresh();

        $contact = $this->makeContact('Fuel UAB', '1');

        $invoice = $this->makeInvoice([
            'contact_id' => $contact->id,
            'invoice_number' => 'P-1',
            'invoice_date' => '2026-05-10',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'sum_inc_vat_eur' => 121,
            'expense_type_id' => $expense->id,
        ]);

        $written = InvoiceDefaultFinanceLines::apply($invoice);
        $this->assertSame(3, $written);

        $invoice->load('financeLines.invoiceCode');
        $byCode = $invoice->financeLines->keyBy(fn ($line) => $line->invoiceCode->code);

        $this->assertSame(100.0, (float) $byCode['6208']->debit);
        $this->assertSame(21.0, (float) $byCode['2441']->debit);
        $this->assertSame(121.0, (float) $byCode['4430']->credit);
    }

    public function test_sales_lines_still_use_customers_revenue_vat(): void
    {
        $income = IncomeType::query()->create(['name' => 'Prekės']);
        $contact = $this->makeContact('Buyer UAB', '2');

        $invoice = $this->makeInvoice([
            'contact_id' => $contact->id,
            'invoice_number' => 'S-1',
            'invoice_date' => '2026-05-10',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'sum_inc_vat_eur' => 121,
            'income_type_id' => $income->id,
        ]);

        InvoiceDefaultFinanceLines::apply($invoice);
        $invoice->load('financeLines.invoiceCode');
        $byCode = $invoice->financeLines->keyBy(fn ($line) => $line->invoiceCode->code);

        $this->assertSame(121.0, (float) $byCode['2410']->debit);
        $this->assertSame(21.0, (float) $byCode['4492']->credit);
        $this->assertSame(100.0, (float) $byCode['5000']->credit);
    }

    public function test_payroll_month_posts_balanced_accrual(): void
    {
        ExpenseType::query()->create(['name' => 'Atlyginimai']);
        $employee = Employee::query()->create([
            'name' => 'Test',
            'surname' => 'Worker',
            'position' => 'Staff',
        ]);

        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->id,
            'payment_date' => '2026-04-30',
            'base_salary' => 1000,
            'bonus_payment' => 0,
            'gross_amount' => 1000,
            'npd_amount' => 0,
            'sodra_employee_amount' => 195,
            'sodra_employer_amount' => 17.7,
            'gpm_amount' => 200,
            'net_amount' => 605,
            'status' => EmployeeMonthlyPaymentStatus::Payed,
            'is_paid' => true,
        ]);

        $result = PayrollLedgerPoster::postMonth(2026, 4);
        $this->assertNotNull($result);

        $invoice = Invoice::query()->findOrFail($result['invoice_id']);
        $this->assertSame('payroll', $invoice->gl_generated_type);
        $this->assertSame('2026-04', $invoice->gl_generated_key);

        $invoice->load('financeLines.invoiceCode');
        $debit = round((float) $invoice->financeLines->sum('debit'), 2);
        $credit = round((float) $invoice->financeLines->sum('credit'), 2);
        $this->assertEqualsWithDelta($debit, $credit, 0.001);
        $this->assertEqualsWithDelta(1017.7, $debit, 0.001);
    }

    public function test_recalculate_from_april_rebuilds_lines_and_payroll(): void
    {
        $expense = ExpenseType::query()->create(['name' => 'Kita']);
        ExpenseTypeAccountMapper::syncDefaults();
        $contact = $this->makeContact('Shop', '9');

        $this->makeInvoice([
            'contact_id' => $contact->id,
            'invoice_number' => 'OLD',
            'invoice_date' => '2026-03-15',
            'upload_date' => '2026-03-15',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
            'sum_inc_vat_eur' => 10,
            'expense_type_id' => $expense->id,
        ]);

        $april = $this->makeInvoice([
            'contact_id' => $contact->id,
            'invoice_number' => 'APR',
            'invoice_date' => '2026-04-02',
            'upload_date' => '2026-04-02',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'sum_inc_vat_eur' => 50,
            'expense_type_id' => $expense->id,
        ]);

        $employee = Employee::query()->create([
            'name' => 'Test',
            'surname' => 'Worker',
            'position' => 'Staff',
        ]);
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $employee->id,
            'payment_date' => '2026-04-30',
            'gross_amount' => 1000,
            'sodra_employee_amount' => 195,
            'sodra_employer_amount' => 18,
            'gpm_amount' => 200,
            'net_amount' => 605,
            'status' => EmployeeMonthlyPaymentStatus::Payed,
            'is_paid' => true,
        ]);

        $result = LedgerRecalculator::recalculate('2026-04-01');

        $this->assertSame(1, $result['invoices']);
        $this->assertGreaterThan(0, $result['invoice_lines']);
        $this->assertSame(1, $result['payroll_invoices']);
        $this->assertTrue($april->fresh()->financeLines()->exists());
        $this->assertFalse(Invoice::query()->where('invoice_number', 'OLD')->first()->financeLines()->exists());
        $this->assertSame(0, $result['coverage']['invoices_missing_lines']);
        $this->assertSame(1, $result['coverage']['payroll_months_posted']);
    }
}
