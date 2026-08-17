<?php

namespace App\Filament\Admin\Resources\LtHolidays\Pages;

use App\Filament\Admin\Resources\LtHolidays\LtHolidayResource;
use Filament\Resources\Pages\EditRecord;

class EditLtHoliday extends EditRecord
{
    protected static string $resource = LtHolidayResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateLtHoliday::normalizeRuleFields($data);
    }
}
