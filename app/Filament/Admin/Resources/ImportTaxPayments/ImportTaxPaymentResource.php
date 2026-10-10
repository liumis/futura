<?php

namespace App\Filament\Admin\Resources\ImportTaxPayments;

use App\Enums\CargoStatus;
use App\Enums\ImportTaxPaymentStatus;
use App\Models\CustomsContact;
use App\Models\ImportTaxPayment;
use App\Services\ActivityLogger;
use App\Services\ImportTaxPaymentSepaExporter;
use App\Services\SharepointCustomsInvoiceUploader;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class ImportTaxPaymentResource extends Resource
{
    protected static ?string $model = ImportTaxPayment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Import taxes';

    protected static ?string $modelLabel = 'Import tax payment';

    protected static ?string $pluralModelLabel = 'Import taxes';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 9;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('received_date')
                    ->label('Date')
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

                Tables\Columns\SelectColumn::make('customs_contact_id')
                    ->label('Customs company')
                    ->options(fn (): array => CustomsContact::options())
                    ->placeholder('Select')
                    ->searchable()
                    ->native(false)
                    ->selectablePlaceholder(fn (): bool => CustomsContact::query()->count() !== 1),

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

                Tables\Columns\TextInputColumn::make('service_cost')
                    ->label('Service cost')
                    ->type('number')
                    ->inputMode('decimal')
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->rules(['numeric', 'min:0'])
                    ->width('8rem')
                    ->grow(false)
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Total cost')
                    ->getStateUsing(fn (ImportTaxPayment $record): float => $record->totalCost())
                    ->money(Money::currency())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw('(tax_amount + service_cost) '.$direction);
                    })
                    ->summarize(Tables\Columns\Summarizers\Summarizer::make()
                        ->label('Total')
                        ->using(fn ($query): string => Money::format(
                            round((float) $query->sum('tax_amount') + (float) $query->sum('service_cost'), 2),
                        ))),

                Tables\Columns\SelectColumn::make('status')
                    ->options(ImportTaxPaymentStatus::class)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->sortable(),

                Tables\Columns\TextColumn::make('documents')
                    ->label('Documents')
                    ->getStateUsing(fn (ImportTaxPayment $record): int => $record->documentCount())
                    ->formatStateUsing(fn (int $state): string => $state.' file(s)')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
            ])
            ->recordUrl(null)
            ->defaultSort('received_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ImportTaxPaymentStatus::options()),
            ])
            ->recordActions([
                Action::make('makePayment')
                    ->label('Make payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ImportTaxPayment $record): string => 'Generate SEPA payment — WO #'.$record->cargo_id)
                    ->modalDescription('Downloads a SEPA pain.001 file, marks this payment as payed, and sets the warehouse order to Import fee payed.')
                    ->modalSubmitActionLabel('Generate SEPA')
                    ->action(fn (ImportTaxPayment $record): ?StreamedResponse => self::makeSepaPayments(
                        Collection::make([$record]),
                    )),

                Action::make('documents')
                    ->label('Documents')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading(fn (ImportTaxPayment $record): string => 'Documents for warehouse order #'.$record->cargo_id)
                    ->modalSubmitActionLabel('Upload additional')
                    ->schema(fn (ImportTaxPayment $record): array => [
                        Forms\Components\Placeholder::make('sharepoint_files')
                            ->label('On SharePoint')
                            ->content($record->documentsListHtml())
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('additional_documents')
                            ->label('Add additional documents')
                            ->multiple()
                            ->disk('public')
                            ->directory('import-tax-documents-tmp')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::withExistingNote('Uploaded files are stored on SharePoint next to the customs invoice. Temporary local copies are removed after upload.'))
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->action(function (ImportTaxPayment $record, array $data): void {
                        $paths = $data['additional_documents'] ?? [];

                        if (! is_array($paths)) {
                            $paths = filled($paths) ? [(string) $paths] : [];
                        }

                        $paths = array_values(array_filter($paths, fn ($path): bool => is_string($path) && $path !== ''));

                        if ($paths === []) {
                            Notification::make()
                                ->title('No new files selected')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            SharepointCustomsInvoiceUploader::ingestAdditionalDocuments($record, $paths);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Upload failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Documents uploaded to SharePoint')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('makePayment')
                        ->label('Make payment')
                        ->icon('heroicon-o-banknotes')
                        ->requiresConfirmation()
                        ->modalHeading('Generate SEPA payments')
                        ->modalDescription('Downloads one SEPA pain.001 file for the selected rows, marks them as payed, and sets each warehouse order to Import fee payed.')
                        ->modalSubmitActionLabel('Generate SEPA')
                        ->action(fn (Collection $records): ?StreamedResponse => self::makeSepaPayments($records)),
                ]),
            ])
            ->emptyStateHeading('No import tax payments yet')
            ->emptyStateDescription('Payments appear here when a customs invoice is uploaded on a warehouse order.');
    }

    /**
     * Build SEPA XML, mark payments as payed, and set linked cargos to Import fee payed.
     * Shared by Finances → Import taxes and Cargos → Pay.
     *
     * @param  Collection<int, ImportTaxPayment>  $payments
     */
    public static function makeSepaPayments(Collection $payments): ?StreamedResponse
    {
        if ($payments->isEmpty()) {
            Notification::make()
                ->title('Select at least one import tax payment')
                ->warning()
                ->send();

            return null;
        }

        $payments->loadMissing(['customsContact', 'cargo']);

        foreach ($payments as $payment) {
            if (! $payment->customs_contact_id) {
                $payment->update([
                    'customs_contact_id' => CustomsContact::defaultId(),
                ]);
                $payment->refresh();
                $payment->load('customsContact');
            }
        }

        try {
            $xml = app(ImportTaxPaymentSepaExporter::class)->buildXml($payments);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not make payment')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        foreach ($payments as $payment) {
            $payment->update([
                'status' => ImportTaxPaymentStatus::Payed,
            ]);

            $cargo = $payment->cargo;

            if ($cargo !== null && $cargo->status !== CargoStatus::Received) {
                $cargo->update([
                    'status' => CargoStatus::ImportFeePayed,
                ]);
            }
        }

        $filename = 'sepa-import-tax-'.now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded('SEPA import tax payment', 'xml', properties: [
            'file_name' => $filename,
            'import_tax_payment_ids' => $payments->map(fn (ImportTaxPayment $payment): int => (int) $payment->getKey())->all(),
            'count' => $payments->count(),
        ]);

        Notification::make()
            ->title('SEPA payment generated')
            ->body('Payment(s) marked as payed. Warehouse order status set to Import fee payed.')
            ->success()
            ->send();

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * @deprecated Use makeSepaPayments()
     *
     * @param  Collection<int, ImportTaxPayment>  $payments
     */
    public static function downloadSepa(Collection $payments): ?StreamedResponse
    {
        return self::makeSepaPayments($payments);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['cargo.supplier', 'importTax', 'customsContact']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportTaxPayments::route('/'),
        ];
    }
}
