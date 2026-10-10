<?php

namespace App\Filament\Admin\Pages;

use App\Models\Collection;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\CollectionPricing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection as SupportCollection;
use UnitEnum;

class CatalogPricing extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-euro';

    protected static ?string $navigationLabel = 'Pricing';

    protected static ?string $title = 'Pricing';

    protected static ?string $slug = 'pricing';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.admin.pages.catalog-pricing';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    /**
     * @return SupportCollection<int, Collection>
     */
    public function getCollectionsProperty(): SupportCollection
    {
        return Collection::query()->orderBy('name')->get();
    }

    public function customsFor(Collection $collection): string
    {
        if ($collection->port_price === null) {
            return '—';
        }

        return CollectionPricing::formatAmount(
            CollectionPricing::customs((float) $collection->port_price)
        );
    }

    public function costFor(Collection $collection): string
    {
        if ($collection->port_price !== null) {
            return CollectionPricing::formatAmount(
                CollectionPricing::costPrice(
                    (float) $collection->port_price,
                    $collection->agent_fee !== null ? (float) $collection->agent_fee : null,
                    $collection->other_cost !== null ? (float) $collection->other_cost : null,
                )
            );
        }

        return CollectionPricing::formatAmount(
            $collection->cost !== null ? (float) $collection->cost : null
        );
    }

    public function priceFor(Collection $collection): string
    {
        $agentFee = $collection->agent_fee !== null ? (float) $collection->agent_fee : null;

        $cost = $collection->port_price !== null
            ? CollectionPricing::costPrice(
                (float) $collection->port_price,
                $agentFee,
                $collection->other_cost !== null ? (float) $collection->other_cost : null,
            )
            : ($collection->cost !== null ? (float) $collection->cost : null);

        if ($cost !== null && $collection->markup_percent !== null) {
            return CollectionPricing::formatAmount(
                CollectionPricing::sellPrice($cost, (float) $collection->markup_percent)
            );
        }

        return CollectionPricing::formatAmount(
            $collection->price !== null ? (float) $collection->price : null
        );
    }

    public function renameCollection(int $collectionId, ?string $name = null): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            Notification::make()
                ->title('Name required')
                ->danger()
                ->send();

            return;
        }

        Collection::query()->whereKey($collectionId)->update(['name' => $name]);
    }

    public function saveField(int $collectionId, string $field, ?string $value = null): void
    {
        $allowed = ['port_price', 'agent_fee', 'other_cost', 'markup_percent'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        $parsed = CollectionPricing::parseNullableNumber($value);

        if ($parsed === false) {
            Notification::make()
                ->title('Invalid value')
                ->body('Enter a number ≥ 0, or clear the cell.')
                ->danger()
                ->send();

            return;
        }

        $collection = Collection::query()->findOrFail($collectionId);
        $collection->{$field} = $parsed;

        if ($field === 'port_price' && $parsed !== null && $collection->agent_fee === null) {
            $collection->agent_fee = CollectionPricing::DEFAULT_AGENT_FEE;
        }

        $collection->applyPricingBreakdown();
        $collection->save();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('+ Add')
                ->modalHeading('Add collection pricing')
                ->form([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false)
                        ->required(),
                    Select::make('warehouse_id')
                        ->label('Warehouse')
                        ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->default(fn (): ?int => Warehouse::query()->where('is_default', true)->value('id')
                            ?? Warehouse::query()->orderBy('name')->value('id')),
                    TextInput::make('port_price')
                        ->label('Base price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                    TextInput::make('agent_fee')
                        ->label('Agent')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->default(CollectionPricing::DEFAULT_AGENT_FEE),
                    TextInput::make('other_cost')
                        ->label('Other')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->default(0.30),
                    TextInput::make('markup_percent')
                        ->label('Markup %')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                ])
                ->action(function (array $data): void {
                    $agent = array_key_exists('agent_fee', $data) && $data['agent_fee'] !== null && $data['agent_fee'] !== ''
                        ? $data['agent_fee']
                        : CollectionPricing::DEFAULT_AGENT_FEE;

                    $collection = new Collection([
                        'name' => $data['name'],
                        'supplier_id' => $data['supplier_id'],
                        'warehouse_id' => $data['warehouse_id'],
                        'port_price' => $data['port_price'] ?? null,
                        'agent_fee' => $agent,
                        'other_cost' => $data['other_cost'] ?? null,
                        'markup_percent' => $data['markup_percent'] ?? null,
                        'cost' => 0,
                        'price' => 0,
                    ]);
                    $collection->applyPricingBreakdown();
                    if ($collection->cost === null) {
                        $collection->cost = 0;
                    }
                    if ($collection->price === null) {
                        $collection->price = 0;
                    }
                    $collection->save();

                    Notification::make()
                        ->title('Collection added')
                        ->success()
                        ->send();
                }),
        ];
    }
}
