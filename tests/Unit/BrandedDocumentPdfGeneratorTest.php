<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Services\BrandedDocumentPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandedDocumentPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_uses_name_date_and_sanitized_body(): void
    {
        CompanySetting::query()->create([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '306740042',
            'company_address' => 'Ringuvos g. 30-2',
            'company_email' => 'info@futuratextiles.eu',
        ]);

        $payload = BrandedDocumentPdfGenerator::build(
            'Internal memo',
            '2026-08-22 10:00:00',
            '<p>Hello <strong>team</strong></p><script>alert(1)</script><p onclick="x()">Safe</p>',
        );

        $this->assertSame('Internal memo', $payload['documentName']);
        $this->assertSame('2026-08-22', $payload['documentDate']);
        $this->assertSame('Futura Textiles Europe, UAB', $payload['companyName']);
        $this->assertSame('306740042', $payload['companyId']);
        $this->assertStringContainsString('<strong>team</strong>', $payload['bodyHtml']);
        $this->assertStringNotContainsString('<script>', $payload['bodyHtml']);
        $this->assertStringNotContainsString('onclick', $payload['bodyHtml']);

        $pdf = BrandedDocumentPdfGenerator::generate(
            'Internal memo',
            '2026-08-22',
            '<p>Hello</p>',
        );

        $this->assertNotSame('', $pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
