<?php

namespace Database\Seeders;

use App\Models\VatRate;
use Illuminate\Database\Seeder;

class VatRateSeeder extends Seeder
{
    /**
     * @var list<array{classificator: string, rate: float|null, description: string|null}>
     */
    private const RATES = [
        [
            'classificator' => 'PVM1',
            'rate' => 21,
            'description' => null,
        ],
        [
            'classificator' => 'PVM2',
            'rate' => 12,
            'description' => 'iki 2025-12-31 buvo 9 %',
        ],
        [
            'classificator' => 'PVM3',
            'rate' => 5,
            'description' => null,
        ],
        [
            'classificator' => 'PVM4',
            'rate' => 0,
            'description' => null,
        ],
        [
            'classificator' => 'PVM15',
            'rate' => null,
            'description' => 'Netaikomas (ne PVM objektas Lietuvoje)',
        ],
        [
            'classificator' => 'PVM16',
            'rate' => null,
            'description' => 'Paprastai 21 %, 12 % arba 5 % pagal įsigytą prekę/paslaugą (reverse charge)',
        ],
        [
            'classificator' => 'PVM25',
            'rate' => null,
            'description' => 'Pagal taikomą tarifą (dažniausiai 21 %, bet ne visada)',
        ],
        [
            'classificator' => 'PVM32',
            'rate' => 21,
            'description' => 'nuo maržos',
        ],
        [
            'classificator' => 'PVM33',
            'rate' => 0,
            'description' => 'nuo maržos',
        ],
        [
            'classificator' => 'PVM34',
            'rate' => null,
            'description' => 'Netaikomas',
        ],
    ];

    public function run(): void
    {
        foreach (self::RATES as $row) {
            VatRate::query()->updateOrCreate(
                ['classificator' => $row['classificator']],
                [
                    'rate' => $row['rate'],
                    'description' => $row['description'],
                ],
            );
        }
    }
}
