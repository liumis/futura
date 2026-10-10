<?php

namespace Tests\Unit;

use App\Services\BusinessTripDocumentService;
use Tests\TestCase;

class BusinessTripDocumentServiceTest extends TestCase
{
    public function test_ensure_document_type_creates_komandiruote(): void
    {
        $this->refreshDatabase();

        $type = BusinessTripDocumentService::ensureDocumentTypeExists();

        $this->assertSame(BusinessTripDocumentService::DOCUMENT_TYPE_NAME, $type->name);
        $this->assertTrue((bool) $type->group_by_year);

        $again = BusinessTripDocumentService::ensureDocumentTypeExists();
        $this->assertSame($type->getKey(), $again->getKey());
    }

    public function test_normalize_upload_paths_from_string_and_array(): void
    {
        $paths = BusinessTripDocumentService::normalizeUploadPaths([
            'business-trip-uploads/a.pdf',
            'storage/business-trip-uploads/b.pdf',
            '',
            null,
        ]);

        $this->assertSame([
            'business-trip-uploads/a.pdf',
            'business-trip-uploads/b.pdf',
        ], $paths);
    }

    private function refreshDatabase(): void
    {
        $this->artisan('migrate', ['--force' => true]);
    }
}
