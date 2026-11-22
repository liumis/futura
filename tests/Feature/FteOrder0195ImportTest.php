<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FteOrder0195ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_file_is_present_and_well_formed(): void
    {
        $path = database_path('data/fte-orders-0195.json');
        $this->assertTrue(File::exists($path), 'Snapshot JSON missing for prod migration.');

        /** @var array{invoice_count?: int, rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['invoice_count'] ?? null);
        $this->assertCount(1, $payload['rows'] ?? []);
        $this->assertSame('FTE0195', $payload['rows'][0]['invoice_number'] ?? null);
        $this->assertSame('2026-11-16', $payload['rows'][0]['invoice_date'] ?? null);
        $this->assertSame('VINILAS', $payload['rows'][0]['customer_code'] ?? null);
        $this->assertSame(729.0, (float) ($payload['rows'][0]['sum_without_vat'] ?? 0));
        $this->assertCount(4, $payload['rows'][0]['lines'] ?? []);
    }
}
