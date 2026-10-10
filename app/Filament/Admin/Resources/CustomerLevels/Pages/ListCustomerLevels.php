<?php

namespace App\Filament\Admin\Resources\CustomerLevels\Pages;

use App\Filament\Admin\Resources\CustomerLevels\CustomerLevelResource;
use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class ListCustomerLevels extends ListRecords
{
    protected static string $resource = CustomerLevelResource::class;

    protected string $view = 'filament.admin.resources.customer-levels.pages.price-matrix';

    public function getTitle(): string
    {
        $updated = CustomerLevelPrice::query()->max('updated_at');

        $suffix = $updated
            ? ' (updated '.Carbon::parse($updated)->format('Y-m-d').')'
            : '';

        return 'Customer group prices, €/m'.$suffix;
    }

    /**
     * @return SupportCollection<int, Collection>
     */
    public function getCollectionsProperty(): SupportCollection
    {
        return Collection::query()->orderBy('name')->get(['id', 'name', 'price']);
    }

    /**
     * @return SupportCollection<int, CustomerLevel>
     */
    public function getLevelsProperty(): SupportCollection
    {
        return CustomerLevel::query()
            ->with(['customerLevelPrices'])
            ->orderBy('name')
            ->get();
    }

    public function priceFor(CustomerLevel $level, int $collectionId): ?string
    {
        $row = $level->customerLevelPrices->firstWhere('collection_id', $collectionId);

        if ($row === null) {
            return null;
        }

        $price = (float) $row->price;

        return fmod($price, 1.0) === 0.0
            ? (string) (int) $price
            : rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
    }

    public function savePrice(int $levelId, int $collectionId, ?string $value = null): void
    {
        $level = CustomerLevel::query()->findOrFail($levelId);
        Collection::query()->findOrFail($collectionId);

        $raw = trim(str_replace(',', '.', (string) $value));

        if ($raw === '' || $raw === '—' || $raw === '-') {
            CustomerLevelPrice::query()
                ->where('customer_level_id', $level->id)
                ->where('collection_id', $collectionId)
                ->delete();

            return;
        }

        if (! is_numeric($raw) || (float) $raw < 0) {
            Notification::make()
                ->title('Invalid price')
                ->body('Enter a number ≥ 0, or clear the cell to remove the override.')
                ->danger()
                ->send();

            return;
        }

        CustomerLevelPrice::query()->updateOrCreate(
            [
                'customer_level_id' => $level->id,
                'collection_id' => $collectionId,
            ],
            [
                'price' => round((float) $raw, 2),
            ],
        );
    }

    public function renameLevel(int $levelId, ?string $name = null): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            Notification::make()
                ->title('Name required')
                ->danger()
                ->send();

            return;
        }

        CustomerLevel::query()->whereKey($levelId)->update(['name' => $name]);
    }

    public function deleteLevel(int $levelId): void
    {
        $level = CustomerLevel::query()->findOrFail($levelId);
        $level->delete();

        Notification::make()
            ->title('Customer group deleted')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add group')
                ->modalHeading('Add customer group')
                ->createAnother(false),
        ];
    }
}
