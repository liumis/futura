<?php

namespace App\Filament\Admin\Resources\ProductReservations\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use App\Models\Collection;
use App\Models\ProductReservation;
use App\Models\ProductType;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ListProductReservations extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = ProductReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add reservation'),
        ];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return ProductReservation::query()
            ->with(['product.productType', 'product.color.collection'])
            ->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'product_type' => [
                'label' => 'Type',
                'can' => fn (ProductReservation $record): bool => $record->product !== null,
                'form' => fn (): Select => Select::make('value')
                    ->label('Type')
                    ->options(fn (): array => ProductType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                'fill' => fn (ProductReservation $record): ?int => $record->product?->product_type_id,
                'save' => function (ProductReservation $record, mixed $value): void {
                    $product = $record->product;
                    if ($product === null) {
                        throw ValidationException::withMessages(['value' => 'This reservation has no product.']);
                    }

                    $typeId = (int) $this->doubleClickValidate($value, ['required', 'integer', 'exists:product_types,id']);
                    $product->update(['product_type_id' => $typeId]);
                },
            ],
            'collection' => [
                'label' => 'Collection',
                'description' => 'Updates the color’s collection for every product that shares this color.',
                'can' => fn (ProductReservation $record): bool => $record->product?->color !== null,
                'form' => fn (): Select => Select::make('value')
                    ->label('Collection')
                    ->options(fn (): array => Collection::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                'fill' => fn (ProductReservation $record): ?int => $record->product?->color?->collection_id,
                'save' => function (ProductReservation $record, mixed $value): void {
                    $color = $record->product?->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $collectionId = (int) $this->doubleClickValidate($value, ['required', 'integer', 'exists:collections,id']);
                    $color->update(['collection_id' => $collectionId]);
                },
            ],
            'color_name' => [
                'label' => 'Color name',
                'description' => 'Updates the color name for every product that shares this color.',
                'can' => fn (ProductReservation $record): bool => $record->product?->color !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Color name')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (ProductReservation $record): ?string => $record->product?->color?->color_name,
                'save' => function (ProductReservation $record, mixed $value): void {
                    $color = $record->product?->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $color->update(['color_name' => $name]);
                },
            ],
            'color_code' => [
                'label' => 'Color code',
                'description' => 'Updates the color code for every product that shares this color.',
                'can' => fn (ProductReservation $record): bool => $record->product?->color !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Color code')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (ProductReservation $record): ?string => $record->product?->color?->color_code,
                'save' => function (ProductReservation $record, mixed $value): void {
                    $color = $record->product?->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $code = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $color->update(['color_code' => $code]);
                },
            ],
        ];
    }
}
