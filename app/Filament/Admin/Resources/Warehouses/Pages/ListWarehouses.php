<?php

namespace App\Filament\Admin\Resources\Warehouses\Pages;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouses extends ListRecords
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['email'] = filled($data['email'] ?? null) ? $data['email'] : null;
                    $data['mail_template_id'] = filled($data['mail_template_id'] ?? null)
                        ? (int) $data['mail_template_id']
                        : null;

                    if (! Warehouse::query()->exists()) {
                        $data['is_default'] = true;
                    }

                    return $data;
                }),
        ];
    }
}
