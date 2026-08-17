<?php

namespace App\Filament\Admin\Resources\Dividends\Pages;

use App\Filament\Admin\Resources\Dividends\DividendResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDividend extends CreateRecord
{
    protected static string $resource = DividendResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DividendResource::mutateFormDataBeforeSave($data);
    }
}
