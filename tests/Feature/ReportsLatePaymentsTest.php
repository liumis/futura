<?php

namespace Tests\Feature;

use App\Enums\PaymentSchedule;
use App\Filament\Admin\Pages\ReportsLatePayments;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\LatePaymentsReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsLatePaymentsTest extends TestCase
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

    public function test_admin_can_open_late_payments_report(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsLatePayments::class)
            ->assertOk()
            ->assertSee('Late payments')
            ->assertSee('Invoices and contracts')
            ->assertSee('Unpaid')
            ->assertSee('Late only')
            ->assertSee('No unpaid invoices or contract payments in this period.');
    }

    public function test_report_lists_late_invoice_and_contract_payment(): void
    {
        $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'REMVESTA',
            'company_id' => '222',
            'company_address' => 'Vilnius',
        ]);
        $admin = User::query()->where('email', 'admin-late-payments@example.com')->firstOrFail();

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => '13157',
            'invoice_date' => '2026-07-01',
            'upload_date' => '2026-07-01',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 1000,
            'vat' => 210,
            'sum_inc_vat' => 1210,
            'payment_term' => 31,
            'paid_amount' => 0,
            'paid' => false,
        ]);
        Payment::factory()->create([
            'name' => 'Warehouse rent',
            'contact_id' => $contact->id,
            'amount' => 800,
            'schedule' => PaymentSchedule::OneTime,
            'due_date' => '2026-07-20',
            'paid' => false,
        ]);

        Livewire::test(ReportsLatePayments::class)
            ->set('data.from', '2026-06-01')
            ->set('data.until', '2026-08-31')
            ->assertSee('13157')
            ->assertSee('REMVESTA')
            ->assertSee('Warehouse rent')
            ->assertSee('Late')
            ->assertSee('Invoice')
            ->assertSee('Contract')
            ->assertSee('Total');
    }

    public function test_late_only_filter_hides_upcoming_rows(): void
    {
        $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Upcoming UAB',
            'company_id' => '333',
            'company_address' => 'Vilnius',
        ]);
        $admin = User::query()->where('email', 'admin-late-payments@example.com')->firstOrFail();

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FUTURE-9',
            'invoice_date' => '2026-08-20',
            'upload_date' => '2026-08-20',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'payment_term' => 30,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        Livewire::test(ReportsLatePayments::class)
            ->set('data.from', '2026-06-01')
            ->set('data.until', '2026-09-30')
            ->assertSee('FUTURE-9')
            ->set('data.status', LatePaymentsReportBuilder::STATUS_LATE)
            ->assertDontSee('FUTURE-9');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-late-payments@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
