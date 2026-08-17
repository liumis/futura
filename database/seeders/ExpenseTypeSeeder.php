<?php

namespace Database\Seeders;

use App\Models\ExpenseType;
use Illuminate\Database\Seeder;

class ExpenseTypeSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const NAMES = [
        'Administracija',
        'IT',
        'Transportas',
        'Marketingas',
        'Paslaugos',
        'Atlyginimai',
        'Prekės',
        'Mokesčiai',
        'Ilgalaikis turtas',
        'Kita',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            ExpenseType::query()->firstOrCreate(['name' => $name]);
        }
    }
}
