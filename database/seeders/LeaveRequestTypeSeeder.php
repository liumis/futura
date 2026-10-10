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
        foreach (LeaveRequestCatalog::TYPES as $name => $meta) {
            LeaveRequestType::query()->updateOrCreate(
                ['name' => $name],
                [
                    'color' => $meta['color'],
                    'timesheet_code' => $meta['timesheet_code'],
                ],
            );

            DocumentType::query()->firstOrCreate(
                ['name' => $name],
                ['group_by_year' => true],
            );
        }
    }
}
