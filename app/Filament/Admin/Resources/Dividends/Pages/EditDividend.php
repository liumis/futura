<?php

namespace App\Filament\Admin\Resources\Dividends\Pages;

use App\Filament\Admin\Resources\Dividends\DividendResource;
use App\Filament\Admin\Resources\Pages\EditRecord;

class EditDividend extends EditRecord
{
    protected static string $resource = DividendResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DividendResource::mutateFormDataBeforeSave($data);
    }
}
