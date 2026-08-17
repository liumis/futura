<?php

namespace App\Filament\Admin\Resources\LtHolidays\Pages;

use App\Filament\Admin\Resources\LtHolidays\LtHolidayResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLtHoliday extends CreateRecord
{
    protected static string $resource = LtHolidayResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalizeRuleFields($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeRuleFields(array $data): array
    {
        if (($data['rule_type'] ?? 'fixed') === 'easter') {
            $data['month'] = null;
            $data['day'] = null;
            $data['easter_offset'] = (int) ($data['easter_offset'] ?? 0);
        } else {
            $data['easter_offset'] = null;
            $data['month'] = (int) ($data['month'] ?? 1);
            $data['day'] = (int) ($data['day'] ?? 1);
        }

        unset($data['rule_type']);

        return $data;
    }
}
