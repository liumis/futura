<?php

namespace App\Filament\Admin\Resources\ManualImports\Pages;

use App\Filament\Admin\Resources\ManualImports\ManualImportResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditManualImport extends EditRecord
{
    protected static string $resource = ManualImportResource::class;

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->update($data);

            return $record;
        });
    }
}
