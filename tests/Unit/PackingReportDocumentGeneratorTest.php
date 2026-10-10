<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Order;
use App\Models\User;
use App\Services\PackingReportDocumentGenerator;
use App\Services\PackingReportPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PackingReportDocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_marks_internal_and_export_customers(): void
    {
        CompanySetting::query()->create(['company_name' => 'UAB Test']);

        $internal = User::factory()->create([
            'company_name' => 'Local Shop',
            'export' => false,
        ]);
        $export = User::factory()->create([
            'company_name' => 'Export GmbH',
            'export' => true,
        ]);

        $internalOrder = Order::factory()->create([
            'user_id' => $internal->getKey(),
            'order_date' => '2026-08-10',
        ]);
        $exportOrder = Order::factory()->create([
            'user_id' => $export->getKey(),
            'order_date' => '2026-08-11',
        ]);

        $payload = PackingReportPdfGenerator::build(collect([$internalOrder]), [
            'audience' => 'internal',
            'period_label' => '2026-08-01 — 2026-08-31',
        ]);

        $this->assertSame('Internal', $payload['audienceLabel']);
        $this->assertSame('Local Shop', $payload['lines'][0]['customer']);
        $this->assertSame('Internal', $payload['lines'][0]['audience']);
        $this->assertSame(1, $payload['ordersCount']);

        $exportPayload = PackingReportPdfGenerator::build(collect([$exportOrder]), [
            'audience' => 'export',
        ]);
        $this->assertSame('Export', $exportPayload['audienceLabel']);
        $this->assertSame('Export', $exportPayload['lines'][0]['audience']);

        $pdf = PackingReportPdfGenerator::generate(collect([$internalOrder]), [
            'audience' => 'internal',
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_document_names_include_audience(): void
    {
        $type = PackingReportDocumentGenerator::ensureDocumentTypeExists();
        $this->assertSame('Packing report', $type->name);
        $this->assertTrue((bool) $type->group_by_year);

        $this->assertSame('internal', PackingReportDocumentGenerator::normalizeAudience('no'));
        $this->assertSame('export', PackingReportDocumentGenerator::normalizeAudience('yes'));
        $this->assertSame('Internal', PackingReportDocumentGenerator::audienceLabel('internal'));

        $this->assertSame(
            'Packing report — Internal — 2026-08-01 — 2026-08-31',
            PackingReportDocumentGenerator::documentName([
                'audience' => 'internal',
                'period_label' => '2026-08-01 — 2026-08-31',
            ]),
        );
        $this->assertSame(
            'packing-report-export-20260801-20260831.pdf',
            PackingReportDocumentGenerator::fileName([
                'audience' => 'export',
                'from' => '2026-08-01',
                'until' => '2026-08-31',
            ]),
        );
    }

    public function test_create_requires_sharepoint_and_does_not_leave_document(): void
    {
        CompanySetting::query()->create(['company_name' => 'UAB Test']);
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->getKey()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        try {
            PackingReportDocumentGenerator::create(
                collect([$order]),
                [
                    'audience' => 'internal',
                    'from' => '2026-08-01',
                    'until' => '2026-08-31',
                    'period_label' => '2026-08-01 — 2026-08-31',
                ],
                $user,
            );
        } finally {
            $this->assertSame(0, Document::query()->count());
            $this->assertTrue(
                DocumentType::query()->where('name', 'Packing report')->exists(),
            );
        }
    }
}
