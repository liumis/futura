<?php

namespace Tests\Feature;

use App\Filament\Customer\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerDocumentsPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        Storage::fake('public');
    }

    public function test_documents_nav_hidden_when_customer_has_no_assignments(): void
    {
        $customer = User::factory()->create(['email' => 'nodocs@example.com']);
        $customer->assignRole(User::ROLE_CUSTOMER);

        $this->actingAs($customer);

        $this->assertFalse(DocumentResource::shouldRegisterNavigation());
    }

    public function test_documents_nav_visible_when_customer_is_assigned(): void
    {
        $customer = User::factory()->create(['email' => 'hasdocs@example.com']);
        $customer->assignRole(User::ROLE_CUSTOMER);

        $document = $this->makeDocument();
        $document->customers()->attach($customer->getKey());

        $this->actingAs($customer);

        $this->assertTrue(DocumentResource::shouldRegisterNavigation());
    }

    public function test_customer_query_only_returns_assigned_documents(): void
    {
        $customer = User::factory()->create(['email' => 'scoped@example.com']);
        $customer->assignRole(User::ROLE_CUSTOMER);

        $mine = $this->makeDocument('Mine');
        $other = $this->makeDocument('Other');
        $mine->customers()->attach($customer->getKey());

        $this->actingAs($customer);

        $ids = DocumentResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$mine->getKey()], $ids);
        $this->assertNotContains($other->getKey(), $ids);
    }

    public function test_assigned_customer_can_download_local_document_file(): void
    {
        $customer = User::factory()->create(['email' => 'download@example.com']);
        $customer->assignRole(User::ROLE_CUSTOMER);

        Storage::disk('public')->put('documents/customer-spec.pdf', '%PDF-1.4 customer spec');
        $document = $this->makeDocument('Customer spec', 'documents/customer-spec.pdf');
        $document->customers()->attach($customer->getKey());

        $response = $this->actingAs($customer)->get(route('documents.file', $document));

        $response->assertOk();
        $this->assertStringContainsString('%PDF-1.4 customer spec', $response->getContent());
    }

    public function test_unassigned_customer_cannot_download_document_file(): void
    {
        $customer = User::factory()->create(['email' => 'denied@example.com']);
        $customer->assignRole(User::ROLE_CUSTOMER);

        Storage::disk('public')->put('documents/secret.pdf', '%PDF-1.4 secret');
        $document = $this->makeDocument('Secret', 'documents/secret.pdf');

        $this->actingAs($customer)
            ->get(route('documents.file', $document))
            ->assertForbidden();
    }

    private function makeDocument(string $name = 'Spec sheet', ?string $filePath = null): Document
    {
        $type = DocumentType::query()->firstOrCreate(['name' => 'Other']);

        return Document::query()->create([
            'document_date' => '2026-11-14',
            'name' => $name,
            'document_type_id' => $type->getKey(),
            'flag_approved' => false,
            'file_path' => $filePath,
        ]);
    }
}
