<?php

namespace App\Filament\Admin\Resources\ManualImports\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\ManualImports\ManualImportResource;
use App\Models\ManualImport;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ListManualImports extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = ManualImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return ManualImport::query()
            ->with(['product.color.collection'])
            ->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'product_code' => [
                'label' => 'Product code',
                'can' => fn (ManualImport $record): bool => $record->product !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Product code')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (ManualImport $record): ?string => $record->product?->product_code,
                'save' => function (ManualImport $record, mixed $value): void {
                    $product = $record->product;
                    if ($product === null) {
                        throw ValidationException::withMessages(['value' => 'This import has no product.']);
                    }

                    $code = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $product->update(['product_code' => $code]);
                },
            ],
        ];
    }
}
