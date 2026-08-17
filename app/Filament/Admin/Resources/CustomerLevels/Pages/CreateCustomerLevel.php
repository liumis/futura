<?php

namespace App\Filament\Admin\Resources\CustomerLevels\Pages;

use App\Filament\Admin\Resources\CustomerLevels\CustomerLevelResource;
use App\Models\Collection;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerLevel extends CreateRecord
{
    protected static string $resource = CustomerLevelResource::class;

    /**
     * @var list<array{collection_id: int, price: mixed}>|null
     */
    protected ?array $pendingPriceRows = null;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'priceRows' => Collection::query()->orderBy('name')->get()->map(fn ($c) => [
                'collection_id' => $c->id,
                'price' => null,
            ])->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingPriceRows = $data['priceRows'] ?? null;
        unset($data['priceRows']);

        return parent::mutateFormDataBeforeCreate($data);
    }

    protected function afterCreate(): void
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
