<?php

namespace App\Filament\Admin\Resources\ProductTypes\Pages;

use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\ProductTypes\ProductTypeResource;
use Filament\Actions;

class EditProductType extends EditRecord
{
    protected static string $resource = ProductTypeResource::class;

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->disabled(fn (): bool => $this->record->products()->exists()),
        ];
    }
}
