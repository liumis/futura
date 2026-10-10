<?php

namespace Database\Seeders;

use App\Models\BusinessTripDailyAllowance;
use Illuminate\Database\Seeder;

class BusinessTripDailyAllowanceSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{list_number: int|null, sort_order: int, country: string, daily_rate: float|int}> $rows */
        $rows = require database_path('data/business_trip_daily_allowances.php');

        foreach ($rows as $row) {
            BusinessTripDailyAllowance::query()->updateOrCreate(
                [
                    'country' => $row['country'],
                ],
                [
                    'list_number' => $row['list_number'],
                    'sort_order' => $row['sort_order'],
                    'daily_rate' => $row['daily_rate'],
                ],
            );
        }
    }
}
