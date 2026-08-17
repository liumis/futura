<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\LeaveRequestType;
use App\Support\LeaveRequestCatalog;
use Illuminate\Database\Seeder;

class LeaveRequestTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LeaveRequestCatalog::TYPES as $name => $color) {
            LeaveRequestType::query()->updateOrCreate(
                ['name' => $name],
                ['color' => $color],
            );

            DocumentType::query()->firstOrCreate(
                ['name' => $name],
                ['group_by_year' => true],
            );
        }
    }
}
