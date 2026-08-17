<?php

namespace Database\Seeders;

use App\Models\IncomeType;
use Illuminate\Database\Seeder;

class IncomeTypeSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const NAMES = [
        'Paslaugos',
        'Prekės',
        'Kita',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            IncomeType::query()->firstOrCreate(['name' => $name]);
        }
    }
}
