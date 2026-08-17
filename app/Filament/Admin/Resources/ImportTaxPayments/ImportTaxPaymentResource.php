<?php

namespace App\Filament\Admin\Resources\ImportTaxPayments;

use App\Filament\Admin\Resources\ImportTaxPayments\Pages;
use App\Models\ImportTaxPayment;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ImportTaxPaymentResource extends Resource
{
    protected static ?string $model = ImportTaxPayment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Import taxes';

    protected static ?string $modelLabel = 'Import tax payment';

    protected static ?string $pluralModelLabel = 'Import taxes';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('received_date')
                    ->label('Received date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cargo.id')
                    ->label('Warehouse order')
                    ->formatStateUsing(fn ($state): string => filled($state) ? '#'.$state : '—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cargo.supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('importTax.name')
                    ->label('Import tax')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tax_rate')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).'%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total qty')
                    ->sortable(),

                Tables\Columns\TextColumn::make('line_value')
                    ->label('Goods value')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('tax_amount')
                    ->label('Import tax')
                    ->money(Money::currency())
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('documents')
                    ->label('Documents')
                    ->formatStateUsing(fn (?array $state): string => count($state ?? []).' file(s)')
                    ->badge()
                    ->color(fn (?array $state): string => filled($state) ? 'success' : 'gray'),
            ])
            ->recordUrl(null)
            ->defaultSort('received_date', 'desc')
            ->recordActions([
                Action::make('documents')
                    ->label('Documents')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading(fn (ImportTaxPayment $record): string => 'Documents for warehouse order #'.$record->cargo_id)
                    ->fillForm(fn (ImportTaxPayment $record): array => [
                        'documents' => $record->documents ?? [],
                    ])
                    ->schema([
                        Forms\Components\FileUpload::make('documents')
                            ->label('Files')
                            ->multiple()
                            ->directory('import-tax-documents')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::note())
                            ->downloadable()
                            ->openable()
                            ->reorderable()
                            ->columnSpanFull(),
                    ])
                    ->action(function (ImportTaxPayment $record, array $data): void {
                        $record->update([
                            'documents' => $data['documents'] ?? [],
                        ]);
                    }),
            ])
            ->emptyStateHeading('No import tax payments yet')
            ->emptyStateDescription('Payments appear here when a warehouse order is received and stock is imported.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['cargo.supplier', 'importTax']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportTaxPayments::route('/'),
        ];
    }
}
