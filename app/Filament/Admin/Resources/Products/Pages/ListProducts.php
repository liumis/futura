<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ListProducts extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return Product::query()->with(['color.collection'])->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'color_name' => [
                'label' => 'Color name',
                'description' => 'Updates the color name for every product that shares this color.',
                'can' => fn (Product $record): bool => $record->color !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Color name')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (Product $record): ?string => $record->color?->color_name,
                'save' => function (Product $record, mixed $value): void {
                    $color = $record->color;
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
                'can' => fn (Product $record): bool => $record->color !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Color code')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (Product $record): ?string => $record->color?->color_code,
                'save' => function (Product $record, mixed $value): void {
                    $color = $record->color;
                    if ($color === null) {
                        throw ValidationException::withMessages(['value' => 'This product has no color.']);
                    }

                    $code = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $color->update(['color_code' => $code]);
                },
            ],
            'name' => [
                'label' => 'Name / Size (m)',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Name / Size (m)')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (Product $record): ?string => $record->name,
                'save' => function (Product $record, mixed $value): void {
                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $record->update(['name' => $name]);
                },
            ],
            'product_code' => [
                'label' => 'Product code',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Product code')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (Product $record): ?string => $record->product_code,
                'save' => function (Product $record, mixed $value): void {
                    $code = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $record->update(['product_code' => $code]);
                },
            ],
            'cost' => [
                'label' => 'Price',
                'description' => 'Catalog / unit price stored on the product (Cost field). Double-click to edit.',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Price')
                    ->required()
                    ->rule(function () {
                        return function (string $attribute, mixed $value, \Closure $fail): void {
                            $normalized = is_string($value)
                                ? str_replace([' ', ','], ['', '.'], trim($value))
                                : $value;
                            if (! is_numeric($normalized) || (float) $normalized < 0) {
                                $fail('Enter a valid price (e.g. 2.70).');
                            }
                        };
                    }),
                'fill' => fn (Product $record): ?string => $record->cost !== null
                    ? number_format((float) $record->cost, 2, '.', '')
                    : null,
                'save' => function (Product $record, mixed $value): void {
                    if (is_string($value)) {
                        $value = str_replace([' ', ','], ['', '.'], trim($value));
                    }
                    $price = (float) $this->doubleClickValidate($value, ['required', 'numeric', 'min:0']);
                    $record->update(['cost' => round($price, 2)]);
                },
            ],
        ];
    }
}
