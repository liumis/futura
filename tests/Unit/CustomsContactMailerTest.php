<?php

namespace Tests\Unit;

use App\Enums\CargoStatus;
use App\Models\Cargo;
use App\Models\CustomsContact;
use App\Models\MailTemplate;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CustomsContactMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomsContactMailerTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_status_allows_customs_documents(): void
    {
        $this->assertFalse(CargoStatus::Draft->allowsCustomsDocuments());
        $this->assertFalse(CargoStatus::Ordered->allowsCustomsDocuments());
        $this->assertFalse(CargoStatus::Shipped->allowsCustomsDocuments());
        $this->assertTrue(CargoStatus::Confirmed->allowsCustomsDocuments());
        $this->assertTrue(CargoStatus::InCustoms->allowsCustomsDocuments());
        $this->assertTrue(CargoStatus::Received->allowsCustomsDocuments());
    }

    public function test_build_body_uses_contact_mail_template_placeholders(): void
    {
        $template = MailTemplate::query()->create([
            'name' => 'Customs default',
            'subject' => 'WO #{order_id} for {company_name}',
            'from_name' => 'Futura Customs',
            'text' => "Hello {company_name},\nOrder #{order_id}\nSupplier: {supplier}\nTracking: {tracking}",
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Acme Leather',
            'email' => 'supplier@example.com',
        ]);

        $contact = CustomsContact::query()->create([
            'company_name' => 'Baltic Customs',
            'email' => 'customs@example.com',
            'mail_template_id' => $template->id,
        ]);

        $cargo = Cargo::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => CargoStatus::Confirmed,
            'tracking' => 'TRK-123',
            'estimated_arrival' => '2026-10-01',
        ]);

        $body = CustomsContactMailer::buildBody($contact, $cargo);

        $this->assertStringContainsString('Hello Baltic Customs', $body);
        $this->assertStringContainsString('Order #'.$cargo->id, $body);
        $this->assertStringContainsString('Supplier: Acme Leather', $body);
        $this->assertStringContainsString('Tracking: TRK-123', $body);

        $subject = CustomsContactMailer::resolveSubject($contact, $cargo);
        $this->assertSame('WO #'.$cargo->id.' for Baltic Customs', $subject);
    }

    public function test_send_updates_customs_email_sent_at(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
        ]);
        $this->actingAs($user);

        $contact = CustomsContact::query()->create([
            'company_name' => 'Baltic Customs',
            'email' => 'customs@example.com',
        ]);

        $cargo = Cargo::factory()->create([
            'status' => CargoStatus::Confirmed,
            'customs_contact_id' => $contact->id,
            'customs_documents' => [
                [
                    'name' => '7-2026-09-08-customs-doc-invoice.pdf',
                    'original_name' => 'invoice.pdf',
                    'path' => 'customs-documents/2026/2026-09/7-2026-09-08-customs-doc-invoice.pdf',
                    'item_id' => null,
                    'web_url' => 'https://example.test/invoice.pdf',
                    'uploaded_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        CustomsContactMailer::send($cargo, $contact, 'Please process.', attachDocuments: false);

        $cargo->refresh();
        $this->assertNotNull($cargo->customs_email_sent_at);
        $this->assertSame($contact->id, $cargo->customs_contact_id);
    }
}
