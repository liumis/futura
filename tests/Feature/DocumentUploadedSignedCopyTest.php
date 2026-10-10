<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Documents\Pages\CreateDocument;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentUploadedSignedCopyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-uploaded-signed-copy@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        DocumentType::query()->firstOrCreate(['name' => 'Other']);
    }

    public function test_create_form_shows_mark_as_signed_and_hides_dokobit_when_checked(): void
    {
        Livewire::test(CreateDocument::class)
            ->assertFormFieldIsVisible('mark_as_signed')
            ->assertFormFieldIsVisible('signer_user_ids')
            ->fillForm([
                'mark_as_signed' => true,
            ])
            ->assertFormFieldIsHidden('signer_user_ids')
            ->assertFormFieldIsHidden('approver_user_ids');
    }

    public function test_mark_as_signed_requires_an_uploaded_file(): void
    {
        $type = DocumentType::query()->where('name', 'Other')->firstOrFail();

        Livewire::test(CreateDocument::class)
            ->fillForm([
                'document_date' => '2026-09-16',
                'name' => 'Paper signed scan',
                'document_type_id' => $type->getKey(),
                'mark_as_signed' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['file_path']);
    }
}
