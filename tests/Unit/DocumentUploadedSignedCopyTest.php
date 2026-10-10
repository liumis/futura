<?php

namespace Tests\Unit;

use App\Enums\DocumentSigningStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentUploadedSignedCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_uploaded_as_signed_locks_without_replacing_the_file(): void
    {
        Storage::fake('public');

        $user = $this->makeUser();
        $path = 'documents/paper-signed.pdf';
        $binary = "%PDF-1.4 signed-scan\n";
        Storage::disk('public')->put($path, $binary);

        $document = $this->makeDocument($user, $path);

        $result = DocumentWorkflow::markUploadedAsSigned($document, $user);

        $this->assertTrue($result->isApproved());
        $this->assertTrue($result->isLocked());
        $this->assertTrue($result->hasCompletedSignature());
        $this->assertSame($path, $result->file_path);
        $this->assertSame($binary, Storage::disk('public')->get($path));
        $this->assertSame([DocumentWorkflow::UPLOADED_SIGNED_COPY_LABEL], $result->signedColumnLabels());
        $this->assertSame(DocumentSigningStatus::Completed, $result->documentSigning?->status);
        $this->assertSame(hash('sha256', $binary), $result->content_hash);
        $this->assertSame($user->getKey(), $result->user_approved_id);
    }

    public function test_mark_uploaded_as_signed_requires_a_file(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument($user, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attach the signed file before marking the document as signed.');

        DocumentWorkflow::markUploadedAsSigned($document, $user);
    }

    public function test_cannot_mark_uploaded_as_signed_twice(): void
    {
        Storage::fake('public');

        $user = $this->makeUser();
        $path = 'documents/paper-signed.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4 signed-scan\n");
        $document = $this->makeDocument($user, $path);

        DocumentWorkflow::markUploadedAsSigned($document, $user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This document is already signed.');

        DocumentWorkflow::markUploadedAsSigned($document->fresh(), $user);
    }

    protected function makeUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create([
            'name' => 'Ada',
            'surname' => 'Signer',
            'email' => 'uploaded-signed-copy@example.com',
        ]);
        $user->assignRole('admin');

        return $user;
    }

    protected function makeDocument(User $user, ?string $filePath): Document
    {
        $type = DocumentType::query()->firstOrCreate(['name' => 'Other']);

        return Document::query()->create([
            'document_date' => '2026-09-16',
            'name' => 'Paper signed scan',
            'document_type_id' => $type->getKey(),
            'user_uploaded_id' => $user->getKey(),
            'flag_approved' => false,
            'file_path' => $filePath,
        ]);
    }
}
