<?php

namespace App\Filament\Admin\Resources\CustomerLevels\Pages;

use App\Filament\Admin\Resources\CustomerLevels\CustomerLevelResource;
use App\Models\Collection;
use App\Filament\Admin\Resources\Pages\EditRecord;

class EditCustomerLevel extends EditRecord
{
    protected static string $resource = CustomerLevelResource::class;

    /**
     * @var list<array{collection_id: int, price: mixed}>|null
     */
    protected ?array $pendingPriceRows = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $data['priceRows'] = Collection::query()->orderBy('name')->get()->map(fn ($c) => [
            'collection_id' => $c->id,
            'price' => $this->record->customerLevelPrices()->firstWhere('collection_id', $c->id)?->price ?? 0,
        ])->toArray();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingPriceRows = $data['priceRows'] ?? null;
        unset($data['priceRows']);

        return parent::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        foreach ($this->pendingPriceRows ?? [] as $row) {
            if (empty($row['collection_id'])) {
                continue;
            }

            $this->record->customerLevelPrices()->updateOrCreate(
                ['collection_id' => $row['collection_id']],
                ['price' => $row['price'] ?? 0]
            );
        }
    }
}
