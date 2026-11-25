<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FDuomenysFrom20261125ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_has_expected_delta_shape(): void
    {
        $path = database_path('data/f-duomenys-from-2026-11-25.json');
        $this->assertFileExists($path);

        /** @var array{line_count?: int, order_groups?: int, min_date?: string, max_date?: string, rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(9, $payload['line_count'] ?? null);
        $this->assertSame(2, $payload['order_groups'] ?? null);
        $this->assertSame('2026-11-25', $payload['min_date'] ?? null);
        $this->assertSame('2026-11-25', $payload['max_date'] ?? null);
        $this->assertCount(9, $payload['rows'] ?? []);

        $codes = array_values(array_unique(array_column($payload['rows'] ?? [], 'customer_code')));
        sort($codes);
        $this->assertSame(['AUTOLEDER', 'PEDROTAVARES'], $codes);

        $items = array_column($payload['rows'] ?? [], 'item');
        sort($items);
        $this->assertSame([
            'AGNONA115FOXTROT30',
            'AGNONA142TURQUOISE30',
            'ARGANO172MERLOT30',
            'BORGA121BLACK30',
            'BORGA161PICANTE30',
            'PALOMA133ARONA30',
            'PALOMA142EDEN30',
            'SARAMO105CAMEL30',
            'SARAMO113BISON30',
        ], $items);
    }
}
