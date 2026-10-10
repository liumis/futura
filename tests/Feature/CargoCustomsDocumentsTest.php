<?php

namespace Tests\Feature;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\Pages\EditCargo;
use App\Models\Cargo;
use App\Models\CustomsContact;
use App\Models\MailTemplate;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CargoCustomsDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_edit_shows_customs_documents_section(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Customs Test Supplier']);
        $cargo = Cargo::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => CargoStatus::Confirmed,
        ]);

        Livewire::test(EditCargo::class, ['record' => $cargo->getKey()])
            ->assertOk()
            ->assertFormFieldExists('customs_document_uploads')
            ->assertActionExists('sendCustomsEmail');
    }

    public function test_draft_edit_hides_customs_document_uploads(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Draft Supplier']);
        $cargo = Cargo::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => CargoStatus::Draft,
        ]);

        Livewire::test(EditCargo::class, ['record' => $cargo->getKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('customs_document_uploads');
    }

    public function test_send_customs_email_action_uses_selected_contact_template(): void
    {
        $this->actingAsAdmin();

        $template = MailTemplate::query()->create([
            'name' => 'Broker A',
            'subject' => 'Docs #{order_id}',
            'from_name' => 'Futura',
            'text' => 'Please handle order #{order_id}.',
        ]);

        $contactA = CustomsContact::query()->create([
            'company_name' => 'Broker A',
            'email' => 'a@customs.test',
            'mail_template_id' => $template->id,
        ]);
        CustomsContact::query()->create([
            'company_name' => 'Broker B',
            'email' => 'b@customs.test',
        ]);

        $cargo = Cargo::factory()->create([
            'status' => CargoStatus::Confirmed,
            'customs_contact_id' => $contactA->id,
            'customs_documents' => [
                [
                    'name' => 'doc.pdf',
                    'original_name' => 'doc.pdf',
                    'path' => 'customs-documents/doc.pdf',
                    'item_id' => null,
                    'web_url' => 'https://example.test/doc.pdf',
                    'uploaded_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        Livewire::test(EditCargo::class, ['record' => $cargo->getKey()])
            ->assertOk()
            ->callAction('sendCustomsEmail', data: [
                'customs_contact_id' => $contactA->id,
                'email_body' => 'Please handle order #'.$cargo->id.'.',
                'attach_documents' => false,
            ])
            ->assertNotified();

        $cargo->refresh();
        $this->assertNotNull($cargo->customs_email_sent_at);
        $this->assertSame($contactA->id, $cargo->customs_contact_id);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-customs-docs@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
