<?php

namespace App\Filament\Admin\Resources\StockManualUpdates\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\StockManualUpdates\StockManualUpdateResource;
use App\Models\Collection;
use App\Models\StockManualUpdate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ListStockManualUpdates extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = StockManualUpdateResource::class;

    public function getTitle(): string
    {
        return 'Write-off history';
    }

    public function getSubheading(): ?string
    {
        return 'History of stock decreases from Stock. Select undocumented rows to create a write-off document — quantities are not edited here.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return StockManualUpdate::query()
            ->with(['product.color.collection'])
            ->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'collection' => [
                'label' => 'Collection',
                'description' => 'Updates the color’s collection for every product that shares this color.',
                'can' => fn (StockManualUpdate $record): bool => $record->product?->color !== null,
                'form' => fn (): Select => Select::make('value')
                    ->label('Collection')
                    ->options(fn (): array => Collection::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                'fill' => fn (StockManualUpdate $record): ?int => $record->product?->color?->collection_id,
                'save' => function (StockManualUpdate $record, mixed $value): void {
                    $color = $record->product?->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $collectionId = (int) $this->doubleClickValidate($value, ['required', 'integer', 'exists:collections,id']);
                    $color->update(['collection_id' => $collectionId]);
                },
            ],
            'color_name' => [
                'label' => 'Color',
                'description' => 'Updates the color name for every product that shares this color.',
                'can' => fn (StockManualUpdate $record): bool => $record->product?->color !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Color')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (StockManualUpdate $record): ?string => $record->product?->color?->color_name,
                'save' => function (StockManualUpdate $record, mixed $value): void {
                    $color = $record->product?->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $color->update(['color_name' => $name]);
                },
            ],
            'product_code' => [
                'label' => 'Product code',
                'can' => fn (StockManualUpdate $record): bool => $record->product !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Product code')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (StockManualUpdate $record): ?string => $record->product?->product_code,
                'save' => function (StockManualUpdate $record, mixed $value): void {
                    $product = $record->product;
                    if ($product === null) {
                        throw ValidationException::withMessages(['value' => 'This write-off has no product.']);
                    }

                    $code = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $product->update(['product_code' => $code]);
                },
            ],
        ];
    }
}
