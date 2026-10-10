<?php

namespace Tests\Feature;

use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_payments_list(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListPayments::class)
            ->assertOk()
            ->assertSee('Payments')
            ->assertSee('Add payment');

        $this->assertNotNull(PaymentResource::getUrl('index'));
    }

    public function test_admin_can_create_a_one_time_payment(): void
    {
        $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Landlord UAB',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);

        Livewire::test(CreatePayment::class)
            ->fillForm([
                'name' => 'Office lease',
                'contact_id' => $contact->id,
                'amount' => 1200,
                'schedule' => PaymentSchedule::OneTime->value,
                'due_date' => '2026-09-01',
                'paid' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('payments', [
            'name' => 'Office lease',
            'contact_id' => $contact->id,
            'amount' => 1200,
            'schedule' => PaymentSchedule::OneTime->value,
            'paid' => 0,
        ]);
    }

    public function test_admin_can_create_a_periodical_payment(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreatePayment::class)
            ->fillForm([
                'name' => 'Software subscription',
                'amount' => 49.90,
                'schedule' => PaymentSchedule::Periodical->value,
                'frequency' => PaymentFrequency::Monthly->value,
                'start_date' => '2026-01-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = Payment::query()->where('name', 'Software subscription')->first();

        $this->assertNotNull($payment);
        $this->assertTrue($payment->isPeriodical());
        $this->assertSame(PaymentFrequency::Monthly, $payment->frequency);
        $this->assertSame('2026-09-01', $payment->nextDueDate(now()->setDate(2026, 8, 15))->toDateString());
    }

    public function test_payments_list_shows_uploaded_agreement_name(): void
    {
        $this->actingAsAdmin();
        Payment::factory()->create([
            'name' => 'Insurance contract',
            'amount' => 300,
        ]);

        Livewire::test(ListPayments::class)
            ->assertSee('Insurance contract');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-payments@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
