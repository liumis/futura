<?php

namespace App\Filament\Admin\Pages;

use App\Models\Product;
use App\Services\ExternalStockImporter;
use App\Services\StockManualUpdateLogger;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

class Stock extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Current stock';

    protected static ?string $title = 'Current stock';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.stock';

    /** @var array<string, int> product id => pending write-off amount */
    public array $writeOffAmounts = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Enter write-off amounts, then click Write off to confirm (optional comment). Increases come from Import to stock / Excel. Document batches later under Manual write-off.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()->with(['productType', 'color.collection']))
            ->columns([
                Tables\Columns\TextColumn::make('productType.name')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('color.collection.name')
                    ->label('Collection')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('color.color_name')
                    ->label('Color/Name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('color.color_code')
                    ->label('Color code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Size (m)')
                    ->getStateUsing(fn (Product $record): ?string => $record->isCatalog() ? null : (string) $record->name)
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product_code')
                    ->label('Product code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('alternative_code')
                    ->label('Alternative code')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cost')
                    ->label('Cost')
                    ->money(Money::currency())
                    ->getStateUsing(fn (Product $record): float => $record->collectionUnitCost())
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Stock items')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_meters')
                    ->label('Stock (m)')
                    ->getStateUsing(fn (Product $record): ?float => $record->sizeMeters() === null
                        ? null
                        : $record->stockMeters())
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }

                        return rtrim(rtrim(number_format((float) $state, 3, '.', ''), '0'), '.');
                    })
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(Product::stockMetersSqlExpression().' '.$direction);
                    }),

                Tables\Columns\TextInputColumn::make('write_off')
                    ->label('Write off')
                    ->type('number')
                    ->inputMode('numeric')
                    ->rules(['nullable', 'integer', 'min:1'])
                    ->width('6rem')
                    ->grow(false)
                    ->alignEnd()
                    ->disabled(fn (Product $record): bool => (int) $record->current_amount < 1)
                    ->tooltip(fn (Product $record): string => (int) $record->current_amount < 1
                        ? 'No stock to write off'
                        : 'Enter amount, then confirm with Write off (max '.(int) $record->current_amount.')')
                    ->getStateUsing(function (Product $record): string {
                        $key = (string) $record->getKey();

                        return isset($this->writeOffAmounts[$key])
                            ? (string) $this->writeOffAmounts[$key]
                            : '';
                    })
                    ->updateStateUsing(function (Product $record, mixed $state): string {
                        $key = (string) $record->getKey();

                        if ($state === null || $state === '') {
                            unset($this->writeOffAmounts[$key]);

                            return '';
                        }

                        $amount = (int) $state;

                        if ($amount < 1) {
                            unset($this->writeOffAmounts[$key]);

                            return '';
                        }

                        $this->writeOffAmounts[$key] = $amount;

                        return (string) $amount;
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type_id')
                    ->label('Type')
                    ->relationship('productType', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('collection')
                    ->label('Collection')
                    ->relationship('color.collection', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([])
            ->defaultSort('product_code')
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No stock items')
            ->emptyStateDescription('Products will appear here once they are added to the catalog.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmWriteOffs')
                ->label('Write off')
                ->icon('heroicon-o-minus-circle')
                ->color('danger')
                ->badge(fn (): ?string => ($count = $this->pendingWriteOffCount()) > 0 ? (string) $count : null)
                ->disabled(fn (): bool => $this->pendingWriteOffCount() === 0)
                ->modalHeading('Confirm write-off')
                ->modalDescription('Stock will be reduced for the lines below. History appears under Manual write-off.')
                ->modalSubmitActionLabel('Confirm write-off')
                ->form(function (): array {
                    $summary = collect($this->pendingWriteOffLines())
                        ->map(fn (array $line): string => sprintf(
                            '%s — write off %s (stock %s)',
                            e((string) $line['code']),
                            e(number_format($line['amount'])),
                            e(number_format($line['stock'])),
                        ))
                        ->implode('<br>');

                    return [
                        Placeholder::make('summary')
                            ->label('Items')
                            ->content(new HtmlString($summary !== '' ? $summary : '—')),
                        Textarea::make('comment')
                            ->label('Comment')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Optional reason for this write-off'),
                    ];
                })
                ->action(function (array $data): void {
                    $this->confirmPendingWriteOffs(
                        filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                    );
                }),
            Action::make('importExcel')
                ->label('Update from Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Update stock from Excel')
                ->modalDescription('Sets current stock from an external warehouse balances file (Prekė / Kiekis). Leather titles (Dirbtinė oda) create or update products; other rows are catalogs. Leather quantities in meters are converted to rolls using the size from the title. Product codes and costs are updated. Rows below “PAVYZDŽIŲ - Pavyzdžių sandėlis” update Reservations instead of warehouse stock.')
                ->modalSubmitActionLabel('Update stock')
                ->form([
                    UploadLimits::apply(
                        FileUpload::make('file')
                            ->label('Excel file')
                            ->acceptedFileTypes([
                                '.xls',
                                '.xlsx',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/octet-stream',
                                'application/CDFV2',
                                'application/x-cfb',
                                'application/vnd.ms-office',
                            ])
                            ->disk('local')
                            ->directory('stock-excel-imports')
                            ->visibility('private')
                            ->required(),
                        'Classic .xls or .xlsx from the external system. Leather is matched or created from the title (collection, color, size). Other rows become catalogs.',
                    ),
                ])
                ->action(function (array $data): void {
                    $this->importStockFromExcel($data['file'] ?? null);
                }),
        ];
    }

    public function pendingWriteOffCount(): int
    {
        return count($this->pendingWriteOffLines());
    }

    /**
     * @return list<array{product: Product, amount: int, code: string, stock: int}>
     */
    public function pendingWriteOffLines(): array
    {
        $lines = [];

        foreach ($this->writeOffAmounts as $productId => $amount) {
            $amount = (int) $amount;

            if ($amount < 1) {
                continue;
            }

            $product = Product::query()->find($productId);

            if (! $product) {
                continue;
            }

            $lines[] = [
                'product' => $product,
                'amount' => $amount,
                'code' => (string) ($product->product_code ?: '#'.$product->getKey()),
                'stock' => (int) $product->current_amount,
            ];
        }

        return $lines;
    }

    public function confirmPendingWriteOffs(?string $comment = null): void
    {
        $lines = $this->pendingWriteOffLines();

        if ($lines === []) {
            Notification::make()
                ->title('Nothing to write off')
                ->body('Enter a write-off amount on at least one product first.')
                ->warning()
                ->send();

            return;
        }

        try {
            DB::transaction(function () use ($lines, $comment): void {
                foreach ($lines as $line) {
                    StockManualUpdateLogger::writeOff($line['product'], $line['amount'], $comment);
                }
            });
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Write-off failed')
                ->body(collect($exception->errors())->flatten()->first() ?: $exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $totalUnits = collect($lines)->sum('amount');
        $this->writeOffAmounts = [];
        $this->resetTable();

        Notification::make()
            ->title('Stock written off')
            ->body(sprintf(
                'Removed %s unit(s) across %s product(s). Logged under Manual write-off.',
                number_format($totalUnits),
                number_format(count($lines)),
            ))
            ->success()
            ->send();
    }

    public function importStockFromExcel(mixed $path): void
    {
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        $relative = is_string($path) ? $path : '';

        if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
            Notification::make()
                ->title('No Excel file selected')
                ->danger()
                ->send();

            return;
        }

        $absolute = Storage::disk('local')->path($relative);

        try {
            $result = app(ExternalStockImporter::class)->import($absolute, basename($relative));
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not update stock')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            Storage::disk('local')->delete($relative);
        }

        $body = sprintf(
            '%s created, %s stock updated, %s unchanged, %s code(s) not imported.',
            number_format($result['created'] ?? 0),
            number_format($result['updated']),
            number_format($result['unchanged']),
            number_format($result['unmatched']),
        );

        if ($result['unmatched_codes'] !== []) {
            $body .= ' Missing: '.implode(', ', array_slice($result['unmatched_codes'], 0, 8));
            if ($result['unmatched'] > 8) {
                $body .= '…';
            }
        }

        if (($result['reservations_updated'] ?? 0) > 0 || ($result['reservations_unchanged'] ?? 0) > 0) {
            $body .= sprintf(
                ' Reservations: %s updated, %s unchanged.',
                number_format($result['reservations_updated'] ?? 0),
                number_format($result['reservations_unchanged'] ?? 0),
            );
        }

        Notification::make()
            ->title('Stock updated from Excel')
            ->body($body)
            ->success()
            ->send();
    }

    /**
     * @return array{products: int, units: int}
     */
    public function stockSummary(): array
    {
        $query = $this->getFilteredTableQuery() ?? Product::query();

        return [
            'products' => (int) (clone $query)->count(),
            'units' => (int) (clone $query)->sum('current_amount'),
        ];
    }
}
