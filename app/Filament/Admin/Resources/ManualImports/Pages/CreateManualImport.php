<?php

namespace App\Filament\Admin\Resources\ManualImports\Pages;

use App\Filament\Admin\Resources\ManualImports\ManualImportResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Resources\Pages\CreateRecord;

class CreateManualImport extends CreateRecord
{
    protected static string $resource = ManualImportResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['imported_at'] ??= now()->toDateString();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn (): Model => static::getModel()::query()->create($data));
    }
}
