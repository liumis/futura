<?php

namespace Database\Seeders;

use App\Support\LtOfficialHolidays;
use Illuminate\Database\Seeder;

class LtHolidaySeeder extends Seeder
{
    public function run(): void
    {
        LtOfficialHolidays::seed();
    }
}
