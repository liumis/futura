<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FDuomenysFrom20261117ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_has_expected_delta_shape(): void
    {
        $path = database_path('data/f-duomenys-from-2026-11-17.json');
        $this->assertFileExists($path);

        /** @var array{line_count?: int, order_groups?: int, min_date?: string, max_date?: string, rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(4, $payload['line_count'] ?? null);
        $this->assertSame(1, $payload['order_groups'] ?? null);
        $this->assertSame('2026-11-17', $payload['min_date'] ?? null);
        $this->assertSame('2026-11-17', $payload['max_date'] ?? null);
        $this->assertCount(4, $payload['rows'] ?? []);

        $items = array_column($payload['rows'] ?? [], 'item');
        sort($items);
        $this->assertSame([
            'AGNONA112TAUPE30',
            'BORGA103BEIGE30',
            'PALOMA121BLACK30',
            'SARAMO113BISON30',
        ], $items);
    }
}
