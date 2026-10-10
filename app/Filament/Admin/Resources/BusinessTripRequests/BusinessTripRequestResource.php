<?php

namespace App\Filament\Admin\Resources\BusinessTripRequests;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Models\BusinessTripDailyAllowance;
use App\Models\BusinessTripRequest;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BusinessTripAllowanceCalculator;
use App\Services\BusinessTripAllowanceSepaExporter;
use App\Services\BusinessTripAllowanceTaxCalculator;
use App\Services\BusinessTripDocumentService;
use App\Services\BusinessTripExpensesExtractor;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class BusinessTripRequestResource extends Resource
{
    protected static ?string $model = BusinessTripRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Business trip requests';

    protected static ?string $modelLabel = 'Business trip request';

    protected static ?string $pluralModelLabel = 'Business trip requests';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'business-trip-requests';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'name',
                        fn (Builder $query) => $query->orderBy('surname')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Employee $employee): string => $employee->fullName(),
                    )
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateFormAmounts($set, $get)),

                Forms\Components\Select::make('business_trip_daily_allowance_id')
                    ->label('Country / city')
                    ->relationship(
                        'dailyAllowance',
                        'country',
                        fn (Builder $query) => $query->orderBy('sort_order')->orderBy('country'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (BusinessTripDailyAllowance $row): string => $row->country.' · '.Money::format((float) $row->daily_rate).'/day',
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (! filled($state)) {
                            $set('daily_rate', null);
                            $set('country_name', null);
                            self::recalculateFormAmounts($set, $get);

                            return;
                        }

                        $allowance = BusinessTripDailyAllowance::query()->find((int) $state);
                        if ($allowance === null) {
                            return;
                        }

                        $set('country_name', $allowance->country);
                        $set('daily_rate', (float) $allowance->daily_rate);
                        self::recalculateFormAmounts($set, $get);
                    }),

                Forms\Components\Hidden::make('country_name'),

                Forms\Components\TextInput::make('daily_rate')
                    ->label('Daily rate (EUR)')
                    ->numeric()
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\DatePicker::make('date_from')
                    ->label('From')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateFormAmounts($set, $get)),

                Forms\Components\DatePicker::make('date_to')
                    ->label('To')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateFormAmounts($set, $get)),

                Forms\Components\TextInput::make('first_day_hours')
                    ->label('First day hours')
                    ->helperText('If less than 4 hours, 20% of the daily rate is paid for that day.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(24)
                    ->step(0.25)
                    ->default(8)
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateFormAmounts($set, $get)),

                Forms\Components\TextInput::make('last_day_hours')
                    ->label('Last day hours')
                    ->helperText('Ignored on single-day trips (first day hours are used). If less than 4 hours, 20% is paid.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(24)
                    ->step(0.25)
                    ->default(8)
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateFormAmounts($set, $get)),

                Forms\Components\TextInput::make('calendar_days')
                    ->label('Calendar days')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('payable_units')
                    ->label('Payable day units')
                    ->helperText('Full days count as 1; short first/last days count as 0.2.')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('amount')
                    ->label('Gross allowance (EUR)')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('salary_snapshot')
                    ->label('Contract salary DU (EUR)')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('month_free_cap_snapshot')
                    ->label('Month tax-free cap (EUR)')
                    ->helperText('50% of DU when salary is below MMA×1.65; shared across trips paid in the same month.')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('non_taxable_amount')
                    ->label('Non-taxable (EUR)')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('taxable_amount')
                    ->label('Taxable (EUR)')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('gpm_amount')
                    ->label('GPM 20% (EUR)')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('payable_amount')
                    ->label('Payable to employee (EUR)')
                    ->helperText('Gross minus GPM. SEPA pays this net amount.')
                    ->prefix('€')
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\TextInput::make('expenses_amount')
                    ->label('Other expenses (EUR)')
                    ->helperText('Tickets, hotels, taxis — auto-filled from expense-report PDFs (“Other expenses total”). Editable inline on the list.')
                    ->prefix('€')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0)
                    ->live(onBlur: true)
                    ->suffixAction(
                        Action::make('fillExpensesFromDocuments')
                            ->icon('heroicon-o-document-magnifying-glass')
                            ->tooltip('Read other expenses from attached documents')
                            ->action(function (?BusinessTripRequest $record, Set $set): void {
                                if ($record === null || ! $record->exists) {
                                    Notification::make()
                                        ->title('Save the trip first')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $result = BusinessTripExpensesExtractor::applyToTrip(
                                    $record->fresh(['documents']),
                                    overwrite: true,
                                );

                                if ($result['updated'] && $result['amount'] !== null) {
                                    $set('expenses_amount', $result['amount']);
                                }

                                Notification::make()
                                    ->title($result['updated'] ? 'Expenses updated from documents' : 'No expenses found')
                                    ->body($result['message'])
                                    ->{$result['updated'] ? 'success' : 'warning'}()
                                    ->send();
                            }),
                    ),

                Forms\Components\Placeholder::make('expected_total_display')
                    ->label('Expected total')
                    ->content(function (Get $get, ?BusinessTripRequest $record): string {
                        $payable = (float) ($get('payable_amount') ?? $record?->payableAmount() ?? 0);
                        $expenses = (float) ($get('expenses_amount') ?? $record?->expensesAmount() ?? 0);

                        return Money::format(round(max(0, $payable) + max(0, $expenses), 2))
                            .' (allowance + expenses)';
                    }),

                Forms\Components\Hidden::make('tax_year'),
                Forms\Components\Hidden::make('tax_month'),
                Forms\Components\Hidden::make('salary_threshold_snapshot'),
                Forms\Components\Hidden::make('is_low_salary_case'),

                Forms\Components\TextInput::make('purpose')
                    ->label('Purpose')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('comment')
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('document_uploads')
                    ->label('Trip documents')
                    ->helperText(UploadLimits::withExistingNote('Orders, reports, tickets, receipts — stored on SharePoint as type “Komandiruotė”. You can add more from the list Documents action.'))
                    ->multiple()
                    ->disk('public')
                    ->directory('business-trip-uploads')
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->dehydrated(false)
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('existing_documents')
                    ->label('Stored documents')
                    ->content(function (?BusinessTripRequest $record): HtmlString {
                        if ($record === null || ! $record->exists) {
                            return new HtmlString('<span class="text-sm text-gray-500">Save the trip first, then upload documents.</span>');
                        }

                        return $record->documentsListHtml();
                    })
                    ->columnSpanFull(),

                Forms\Components\Select::make('status')
                    ->options(BusinessTripRequestStatus::options())
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\Select::make('payment_status')
                    ->label('Payment')
                    ->options(BusinessTripPaymentStatus::options())
                    ->disabled()
                    ->dehydrated(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (BusinessTripRequest $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('country_name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('date_from')
                    ->label('From')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('date_to')
                    ->label('To')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('calendar_days')
                    ->label('Days')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Gross')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('gpm_amount')
                    ->label('GPM')
                    ->money(Money::currency())
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payable_amount')
                    ->label('Payable')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('expenses_amount')
                    ->label('Expenses')
                    ->type('number')
                    ->inputMode('decimal')
                    ->step(0.01)
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->placeholder('0.00')
                    ->afterStateUpdated(function ($state, BusinessTripRequest $record): void {
                        $record->forceFill([
                            'expenses_amount' => round(max(0, (float) ($state ?? 0)), 2),
                        ])->save();
                        $record->syncPaymentStatusFromBankLinks();
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('expected_total')
                    ->label('Total')
                    ->getStateUsing(fn (BusinessTripRequest $record): float => $record->expectedTotal())
                    ->money(Money::currency())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('documents')
                    ->label('Documents')
                    ->html()
                    ->getStateUsing(function (BusinessTripRequest $record): string {
                        $record->loadMissing('documents');

                        if ($record->documents->isEmpty()) {
                            return '—';
                        }

                        $links = $record->documents->flatMap(function (Document $document): array {
                            $fileLinks = $document->attachedFileLinks();
                            if ($fileLinks === []) {
                                $url = $document->displayFileUrl() ?: $document->sharepoint_web_url;
                                if (! filled($url)) {
                                    return [];
                                }

                                return [[
                                    'name' => $document->name ?: 'Document',
                                    'url' => (string) $url,
                                ]];
                            }

                            return collect($fileLinks)
                                ->filter(fn (array $file): bool => filled($file['url'] ?? null))
                                ->map(fn (array $file): array => [
                                    'name' => (string) ($file['name'] ?? $document->name ?: 'Document'),
                                    'url' => (string) $file['url'],
                                ])
                                ->all();
                        });

                        if ($links->isEmpty()) {
                            return '—';
                        }

                        return $links
                            ->map(function (array $file): string {
                                $label = e(\Illuminate\Support\Str::limit((string) $file['name'], 42));

                                return '<a href="'.e($file['url']).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400" title="'.e($file['name']).'">'.$label.'</a>';
                            })
                            ->implode('<br>');
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BusinessTripRequestStatus|string|null $state): string => $state instanceof BusinessTripRequestStatus
                        ? $state->label()
                        : (BusinessTripRequestStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn (BusinessTripRequestStatus|string|null $state): string => match ($state instanceof BusinessTripRequestStatus ? $state : BusinessTripRequestStatus::tryFrom((string) $state)) {
                        BusinessTripRequestStatus::Confirmed => 'success',
                        BusinessTripRequestStatus::Canceled => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (BusinessTripPaymentStatus|string|null $state): string => $state instanceof BusinessTripPaymentStatus
                        ? $state->label()
                        : (BusinessTripPaymentStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn (BusinessTripPaymentStatus|string|null $state): string => match ($state instanceof BusinessTripPaymentStatus ? $state : BusinessTripPaymentStatus::tryFrom((string) $state)) {
                        BusinessTripPaymentStatus::Paid => 'success',
                        BusinessTripPaymentStatus::Partly => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('date_from', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'name',
                        fn (Builder $query) => $query->orderBy('surname')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Employee $employee): string => $employee->fullName(),
                    )
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->options(BusinessTripRequestStatus::options()),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(BusinessTripPaymentStatus::options()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('documents')
                    ->label('Documents')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->modalHeading(fn (BusinessTripRequest $record): string => 'Documents — '.$record->employee?->fullName().' ('.$record->date_from?->format('Y-m-d').')')
                    ->modalSubmitActionLabel('Upload additional')
                    ->schema(fn (BusinessTripRequest $record): array => [
                        Forms\Components\Placeholder::make('sharepoint_files')
                            ->label('On SharePoint')
                            ->content($record->documentsListHtml())
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('additional_documents')
                            ->label('Add additional documents')
                            ->multiple()
                            ->disk('public')
                            ->directory('business-trip-uploads')
                            ->visibility('private')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::withExistingNote('Uploaded files are stored on SharePoint as type “Komandiruotė”. Temporary local copies are removed after upload.'))
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->action(function (BusinessTripRequest $record, array $data): void {
                        $paths = BusinessTripDocumentService::normalizeUploadPaths(
                            $data['additional_documents'] ?? null,
                        );

                        if ($paths === []) {
                            Notification::make()
                                ->title('No new files selected')
                                ->warning()
                                ->send();

                            return;
                        }

                        $user = auth()->user();
                        if (! $user instanceof User) {
                            Notification::make()
                                ->title('Upload failed')
                                ->body('You must be signed in to upload documents.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $document = BusinessTripDocumentService::attachUploads(
                                $record->fresh(['employee']),
                                $paths,
                                $user,
                            );
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
                            ->body($document->name)
                            ->success()
                            ->send();

                        $fill = BusinessTripExpensesExtractor::applyToTrip(
                            $record->fresh(['documents']),
                            overwrite: false,
                        );
                        if ($fill['updated']) {
                            Notification::make()
                                ->title('Expenses filled from documents')
                                ->body($fill['message'])
                                ->success()
                                ->send();
                        }
                    }),
                Action::make('fillExpenses')
                    ->label('Fill expenses')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->tooltip('Read “Other expenses total” from attached expense-report PDFs')
                    ->action(function (BusinessTripRequest $record): void {
                        $result = BusinessTripExpensesExtractor::applyToTrip(
                            $record->fresh(['documents']),
                            overwrite: true,
                        );

                        Notification::make()
                            ->title($result['updated'] ? 'Expenses updated' : 'No expenses found')
                            ->body($result['message'])
                            ->{$result['updated'] ? 'success' : 'warning'}()
                            ->send();
                    }),
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (BusinessTripRequest $record): bool => $record->canConfirm())
                    ->action(function (BusinessTripRequest $record): void {
                        self::confirmRequest($record);
                    }),
                Action::make('sepa')
                    ->label('SEPA payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Generate SEPA payment?')
                    ->modalDescription('Downloads a pain.001 XML and marks this trip as paid.')
                    ->visible(fn (BusinessTripRequest $record): bool => $record->canGenerateSepa())
                    ->action(fn (BusinessTripRequest $record): ?StreamedResponse => self::makeSepaPayments(
                        Collection::make([$record]),
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('fillExpensesBulk')
                        ->label('Fill expenses from documents')
                        ->icon('heroicon-o-calculator')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $updated = 0;
                            $skipped = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof BusinessTripRequest) {
                                    continue;
                                }
                                $result = BusinessTripExpensesExtractor::applyToTrip(
                                    $record->fresh(['documents']),
                                    overwrite: true,
                                );
                                if ($result['updated']) {
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title('Expenses from documents')
                                ->body("Updated {$updated}, no total found on {$skipped}.")
                                ->{$updated > 0 ? 'success' : 'warning'}()
                                ->send();
                        }),
                    BulkAction::make('sepaBulk')
                        ->label('SEPA payment')
                        ->icon('heroicon-o-banknotes')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records): ?StreamedResponse => self::makeSepaPayments(
                            $records->filter(fn (BusinessTripRequest $row): bool => $row->canGenerateSepa())->values(),
                        )),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => true),
                ]),
            ]);
    }

    public static function confirmRequest(BusinessTripRequest $request): void
    {
        if (! $request->canConfirm()) {
            Notification::make()
                ->title('Cannot confirm')
                ->body('Only new unpaid requests with a calculated amount can be confirmed.')
                ->warning()
                ->send();

            return;
        }

        $confirmedAt = now();
        $request->confirmed_by = auth()->id();
        $request->confirmed_at = $confirmedAt;
        $request->status = BusinessTripRequestStatus::Confirmed;
        BusinessTripAllowanceTaxCalculator::applyTo($request, $confirmedAt);
        $request->save();

        BusinessTripAllowanceTaxCalculator::recalculateMonthForEmployee(
            (int) $request->employee_id,
            $confirmedAt,
        );

        Notification::make()
            ->title('Business trip confirmed')
            ->success()
            ->send();
    }

    /**
     * @param  Collection<int, BusinessTripRequest>  $requests
     */
    public static function makeSepaPayments(Collection $requests): ?StreamedResponse
    {
        $requests = $requests
            ->filter(fn (BusinessTripRequest $row): bool => $row->canGenerateSepa())
            ->values();

        if ($requests->isEmpty()) {
            Notification::make()
                ->title('Nothing to pay')
                ->body('Select confirmed, unpaid business trips with an amount.')
                ->warning()
                ->send();

            return null;
        }

        $paidAt = now();
        $requests = BusinessTripAllowanceTaxCalculator::applyPaymentBatchTax($requests, $paidAt);

        try {
            $xml = app(BusinessTripAllowanceSepaExporter::class)->buildXml($requests);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not make payment')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        foreach ($requests as $request) {
            $request->update([
                'payment_status' => BusinessTripPaymentStatus::Paid,
                'paid_at' => $paidAt,
            ]);
        }

        $filename = 'business-trip-allowances-'.now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded('Business trip allowances SEPA', 'xml', properties: [
            'file_name' => $filename,
            'count' => $requests->count(),
            'ids' => $requests->modelKeys(),
            'payable_total' => round($requests->sum(fn (BusinessTripRequest $r): float => $r->payableAmount()), 2),
            'gpm_total' => round((float) $requests->sum('gpm_amount'), 2),
        ]);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    private static function recalculateFormAmounts(Set $set, Get $get): void
    {
        $rate = $get('daily_rate');
        $from = $get('date_from');
        $to = $get('date_to');

        if (! filled($rate) || ! filled($from) || ! filled($to)) {
            $set('calendar_days', null);
            $set('payable_units', null);
            $set('amount', null);
            self::clearFormTaxFields($set);

            return;
        }

        try {
            $result = BusinessTripAllowanceCalculator::calculate(
                (float) $rate,
                Carbon::parse((string) $from),
                Carbon::parse((string) $to),
                filled($get('first_day_hours')) ? (float) $get('first_day_hours') : null,
                filled($get('last_day_hours')) ? (float) $get('last_day_hours') : null,
            );
        } catch (Throwable) {
            $set('calendar_days', null);
            $set('payable_units', null);
            $set('amount', null);
            self::clearFormTaxFields($set);

            return;
        }

        $set('calendar_days', $result['calendar_days']);
        $set('payable_units', $result['payable_units']);
        $set('amount', $result['amount']);

        $employeeId = (int) ($get('employee_id') ?? 0);
        if ($employeeId <= 0) {
            $set('non_taxable_amount', $result['amount']);
            $set('taxable_amount', 0);
            $set('gpm_amount', 0);
            $set('payable_amount', $result['amount']);
            $set('salary_snapshot', null);
            $set('month_free_cap_snapshot', null);
            $set('is_low_salary_case', false);

            return;
        }

        $taxMonth = BusinessTripAllowanceTaxCalculator::resolveTaxMonth(
            dateTo: Carbon::parse((string) $to),
        );

        $recordId = filled($get('id')) ? (int) $get('id') : null;

        $tax = BusinessTripAllowanceTaxCalculator::forRequest(
            (float) $result['amount'],
            $employeeId,
            $taxMonth,
            $recordId,
        );

        $set('tax_year', $tax['tax_year']);
        $set('tax_month', $tax['tax_month']);
        $set('salary_snapshot', $tax['salary_snapshot']);
        $set('salary_threshold_snapshot', $tax['salary_threshold_snapshot']);
        $set('is_low_salary_case', $tax['is_low_salary_case']);
        $set('month_free_cap_snapshot', $tax['month_free_cap_snapshot']);
        $set('non_taxable_amount', $tax['non_taxable_amount']);
        $set('taxable_amount', $tax['taxable_amount']);
        $set('gpm_amount', $tax['gpm_amount']);
        $set('payable_amount', $tax['payable_amount']);
    }

    private static function clearFormTaxFields(Set $set): void
    {
        $set('tax_year', null);
        $set('tax_month', null);
        $set('salary_snapshot', null);
        $set('salary_threshold_snapshot', null);
        $set('is_low_salary_case', false);
        $set('month_free_cap_snapshot', null);
        $set('non_taxable_amount', null);
        $set('taxable_amount', null);
        $set('gpm_amount', null);
        $set('payable_amount', null);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'dailyAllowance', 'confirmedBy']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false
            && ($record instanceof BusinessTripRequest
                ? $record->payment_status !== BusinessTripPaymentStatus::Paid
                : true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinessTripRequests::route('/'),
            'create' => Pages\CreateBusinessTripRequest::route('/create'),
            'edit' => Pages\EditBusinessTripRequest::route('/{record}/edit'),
        ];
    }
}
