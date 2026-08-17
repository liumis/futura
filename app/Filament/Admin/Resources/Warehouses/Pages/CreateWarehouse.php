<?php

namespace App\Filament\Admin\Resources\Warehouses\Pages;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Warehouse;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    protected static string $resource = WarehouseResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['email'] = filled($data['email'] ?? null) ? $data['email'] : null;
        $data['mail_template_id'] = filled($data['mail_template_id'] ?? null)
            ? (int) $data['mail_template_id']
            : null;

        if (! Warehouse::query()->exists()) {
            $data['is_default'] = true;
        }

        return $data;
    }
}
