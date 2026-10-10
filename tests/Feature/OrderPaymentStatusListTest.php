<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderPaymentStatusListTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_shows_payment_status_from_linked_invoices(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('customer');

        $admin = User::factory()->create(['email' => 'admin-order-payment@example.com']);
        $admin->assignRole('admin');

        $customer = User::factory()->create(['company_name' => 'Payment Status Co']);
        $customer->assignRole('customer');

        $contact = Contact::query()->create([
            'company_name' => 'Payment Status Co',
            'company_id' => '100000001',
            'company_address' => 'Vilnius',
        ]);

        $unpaidOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Shipped,
            'amount' => 100,
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->id,
            'order_id' => $unpaidOrder->id,
            'invoice_number' => 'INV-UNPAID',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $admin->id,
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'paid_amount' => 0,
            'paid' => false,
        ]);

        $partlyOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Shipped,
            'amount' => 200,
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->id,
            'order_id' => $partlyOrder->id,
            'invoice_number' => 'INV-PART',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $admin->id,
            'pdf_path' => '',
            'sum_without_vat' => 200,
            'vat' => 0,
            'sum_inc_vat' => 200,
            'paid_amount' => 50,
            'paid' => false,
        ]);

        $paidOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'amount' => 80,
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->id,
            'order_id' => $paidOrder->id,
            'invoice_number' => 'INV-PAID',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $admin->id,
            'pdf_path' => '',
            'sum_without_vat' => 80,
            'vat' => 0,
            'sum_inc_vat' => 80,
            'paid_amount' => 80,
            'paid' => true,
        ]);

        $noInvoiceOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Draft,
            'amount' => 10,
        ]);

        $this->assertSame('unpaid', $unpaidOrder->fresh(['invoices'])->paymentSettlementStatus());
        $this->assertSame('partly_paid', $partlyOrder->fresh(['invoices'])->paymentSettlementStatus());
        $this->assertSame('paid', $paidOrder->fresh(['invoices'])->paymentSettlementStatus());
        $this->assertNull($noInvoiceOrder->fresh(['invoices'])->paymentSettlementStatus());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertSee('Payment status')
            ->assertSee('Not paid')
            ->assertSee('Partly paid')
            ->assertSee('Paid')
            ->assertSee('paid €50.00')
            ->assertSee('left €150.00');
    }
}
