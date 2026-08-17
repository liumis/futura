<?php

namespace App\Filament\Admin\Resources\Collections\Pages;

use App\Filament\Admin\Resources\Collections\CollectionResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions;

class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    protected function buildHeaderActions(): array
    {
        return [
            Actions\Action::make('addNew')
                ->label('Add new')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => static::getResource()::getUrl('create'))
                ->color('primary'),
            Actions\DeleteAction::make(),
        ];
    }
}
