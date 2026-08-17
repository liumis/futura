<?php

namespace App\Filament\Admin\Resources\Dividends;

use App\Filament\Admin\Resources\Dividends\Pages;
use App\Enums\DividendPaymentReportStatus;
use App\Enums\DividendPaymentStatus;
use App\Models\Dividend;
use App\Support\Money;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DividendPaymentReportApprover;
use App\Services\LithuanianDividendCalculator;
use App\Services\PayrollAuthoritySepaExporter;
use App\Services\SepaPain001Exporter;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class DividendResource extends Resource
{
    protected static ?string $model = Dividend::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Dividend payments';

    protected static ?string $modelLabel = 'Dividend';

    protected static ?string $pluralModelLabel = 'Dividend payments';

    protected static string|UnitEnum|null $navigationGroup = 'People & contracts';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'name',
                        fn (Builder $query) => $query
                            ->where('shareholder_percentage', '>', 0)
                            ->orderBy('surname')
                            ->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Employee $employee): string => $employee->fullName(),
                    )
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->disabled(fn (?Dividend $record): bool => $record?->isLocked() ?? false),

                Forms\Components\DatePicker::make('date')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->disabled(fn (?Dividend $record): bool => $record?->isLocked() ?? false),

                Forms\Components\TextInput::make('amount')
                    ->label('Gross (amount)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $gross = (float) str_replace(',', '.', (string) ($state ?? 0));
                        $tax = app(LithuanianDividendCalculator::class)->calculate($gross);
                        $set('gpm_amount', number_format($tax['gpm'], 2, '.', ''));
                        $set('net_amount', number_format($tax['net'], 2, '.', ''));
                    }),

                Forms\Components\TextInput::make('gpm_amount')
                    ->label('GPM 20%')
                    ->numeric()
                    ->prefix(Money::prefix())
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('net_amount')
                    ->label('Net to employee')
                    ->numeric()
                    ->prefix(Money::prefix())
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('comment')
                    ->label('Comment')
                    ->maxLength(255)
                    ->default('')
                    ->disabled(fn (?Dividend $record): bool => $record?->isLocked() ?? false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (Dividend $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Gross')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('gpm_amount')
                    ->label('GPM 20%')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Net to employee')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('comment')
                    ->label('Comment')
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:255'])
                    ->disabled(fn (Dividend $record): bool => $record->isLocked())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Payment status')
                    ->badge()
                    ->color(fn (Dividend $record): string => match ($record->status) {
                        DividendPaymentStatus::Open => 'gray',
                        DividendPaymentStatus::Payed => 'success',
                        DividendPaymentStatus::Wrong => 'danger',
                    })
                    ->getStateUsing(fn (Dividend $record): string => $record->status?->label() ?? '—'),

                Tables\Columns\TextColumn::make('dividendPaymentReport.status')
                    ->label('Report')
                    ->badge()
                    ->color(function (Dividend $record): string {
                        return match ($record->dividendPaymentReport?->status) {
                            DividendPaymentReportStatus::Confirmed => 'success',
                            DividendPaymentReportStatus::WaitingConfirmations => 'warning',
                            DividendPaymentReportStatus::Created => 'gray',
                            default => 'gray',
                        };
                    })
                    ->getStateUsing(fn (Dividend $record): string => $record->dividendPaymentReport?->status?->label() ?? '—')
                    ->description(function (Dividend $record): ?string {
                        $report = $record->dividendPaymentReport;
                        if ($report === null || $report->status !== DividendPaymentReportStatus::WaitingConfirmations) {
                            return null;
                        }

                        $pending = $report->pendingApprovers()
                            ->get()
                            ->map(fn (User $u): string => trim($u->fullName()) ?: (string) ($u->email ?? 'User #'.$u->id))
                            ->values()
                            ->all();

                        return $pending !== [] ? 'Missing: '.implode(', ', $pending) : null;
                    })
                    ->wrap(),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                EditAction::make(),

                Action::make('confirmDividendReport')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm dividend report')
                    ->modalDescription('Confirm this report to record your approval.')
                    ->modalSubmitActionLabel('Confirm')
                    ->visible(function (Dividend $record): bool {
                        $userId = auth()->id();
                        if (! $userId || $record->dividendPaymentReport === null) {
                            return false;
                        }

                        $report = $record->dividendPaymentReport;
                        return $report->status === DividendPaymentReportStatus::WaitingConfirmations
                            && $report->userHasPendingApproval((int) $userId);
                    })
                    ->action(function (Dividend $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            DividendPaymentReportApprover::confirmBy($record->dividendPaymentReport, $user);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Confirmation failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Dividend report confirmed')
                            ->success()
                            ->send();
                    }),

                Action::make('payed')
                    ->label('Payed')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Mark as payed')
                    ->modalDescription('Mark this dividend as payed?')
                    ->modalSubmitActionLabel('Payed')
                    ->visible(fn (Dividend $record): bool => $record->status === DividendPaymentStatus::Open)
                    ->action(function (Dividend $record): void {
                        $record->markPayed();

                        Notification::make()
                            ->title('Dividend marked as payed')
                            ->success()
                            ->send();
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel dividend')
                    ->modalDescription('Cancel this dividend?')
                    ->modalSubmitActionLabel('Cancel')
                    ->visible(fn (Dividend $record): bool => $record->status === DividendPaymentStatus::Payed)
                    ->action(function (Dividend $record): void {
                        $record->markWrong();

                        Notification::make()
                            ->title('Dividend cancelled')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('createDividendReport')
                    ->label('Create report')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->button()
                    ->modalHeading('Create dividend payment report')
                    ->modalDescription('Select approvers who must confirm this dividend report.')
                    ->modalSubmitActionLabel('Create report')
                    ->form([
                        Forms\Components\Select::make('approver_user_ids')
                            ->label('Approvals needed from')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->options(fn (): array => self::approverUserOptions()),
                    ])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        $records = $records->filter(function (Dividend $d): bool {
                            return $d->status === DividendPaymentStatus::Open
                                && blank($d->dividend_payment_report_id);
                        });

                        if ($records->isEmpty()) {
                            Notification::make()
                                ->title('Select at least one open dividend without a report')
                                ->warning()
                                ->send();

                            return;
                        }

                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            DividendPaymentReportApprover::create(
                                $records,
                                $data['approver_user_ids'] ?? [],
                                $user,
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not create dividend report')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Dividend report created')
                            ->success()
                            ->send();
                    }),

                BulkActionGroup::make([
                    BulkAction::make('exportSepaXmlDividends')
                        ->label('Export SEPA (dividends)')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Collection $records) {
                            $open = $records->filter(fn (Dividend $d): bool => $d->status === DividendPaymentStatus::Open);

                            if ($open->isEmpty()) {
                                Notification::make()
                                    ->title('Select at least one open dividend')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $open->load('employee');
                            $calculator = app(LithuanianDividendCalculator::class);
                            foreach ($open as $dividend) {
                                if ($dividend->gpm_amount === null || $dividend->net_amount === null) {
                                    $tax = $calculator->calculate((float) $dividend->amount);
                                    $dividend->applyTaxSnapshot([
                                        'gpm' => $tax['gpm'],
                                        'net' => $tax['net'],
                                    ]);
                                }
                            }

                            try {
                                $xml = app(SepaPain001Exporter::class)->buildXml($open);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('SEPA export failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $filename = 'sepa-dividends-'.now()->format('Ymd-His').'.xml';

                            ActivityLogger::logReportDownloaded('SEPA dividends export', 'xml', properties: [
                                'file_name' => $filename,
                                'dividend_ids' => $open->modelKeys(),
                                'count' => $open->count(),
                            ]);

                            return response()->streamDownload(function () use ($xml): void {
                                echo $xml;
                            }, $filename, [
                                'Content-Type' => 'application/xml; charset=UTF-8',
                            ]);
                        }),

                    BulkAction::make('exportVmiXmlDividends')
                        ->label('Export VMI (GPM)')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (Collection $records) {
                            $selected = $records->filter(function (Dividend $d): bool {
                                return in_array($d->status, [
                                    DividendPaymentStatus::Open,
                                    DividendPaymentStatus::Payed,
                                ], true);
                            });

                            if ($selected->isEmpty()) {
                                Notification::make()
                                    ->title('Select open or payed dividends')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $selected->load('employee');
                            $calculator = app(LithuanianDividendCalculator::class);
                            foreach ($selected as $dividend) {
                                if ($dividend->gpm_amount === null || $dividend->net_amount === null) {
                                    $tax = $calculator->calculate((float) $dividend->amount);
                                    $dividend->applyTaxSnapshot([
                                        'gpm' => $tax['gpm'],
                                        'net' => $tax['net'],
                                    ]);
                                }
                            }

                            try {
                                $xml = app(PayrollAuthoritySepaExporter::class)->buildVmiXml($selected);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('VMI export failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $filename = 'sepa-vmi-gpm-dividends-'.now()->format('Ymd-His').'.xml';

                            ActivityLogger::logReportDownloaded('VMI GPM dividends export', 'xml', properties: [
                                'file_name' => $filename,
                                'dividend_ids' => $selected->modelKeys(),
                                'count' => $selected->count(),
                            ]);

                            return response()->streamDownload(function () use ($xml): void {
                                echo $xml;
                            }, $filename, [
                                'Content-Type' => 'application/xml; charset=UTF-8',
                            ]);
                        }),

                    BulkAction::make('exportDividendsXls')
                        ->label('Export XLS')
                        ->icon('heroicon-o-document-arrow-down')
                        ->action(function (Collection $records) {
                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('No dividends selected')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $fileName = 'dividends-report-'.now()->format('Ymd-His').'.xls';

                            $rows = $records->values();
                            $calculator = app(LithuanianDividendCalculator::class);
                            foreach ($rows as $dividend) {
                                if ($dividend->gpm_amount === null || $dividend->net_amount === null) {
                                    $tax = $calculator->calculate((float) $dividend->amount);
                                    $dividend->applyTaxSnapshot([
                                        'gpm' => $tax['gpm'],
                                        'net' => $tax['net'],
                                    ]);
                                }
                            }

                            ActivityLogger::logReportDownloaded('Dividends XLS', 'xls', properties: [
                                'file_name' => $fileName,
                                'dividend_ids' => $rows->modelKeys(),
                                'count' => $rows->count(),
                            ]);

                            return response()->streamDownload(function () use ($rows): void {
                                echo '<table border="1"><tr>';

                                foreach ([
                                    'Date',
                                    'Person',
                                    'Gross',
                                    'GPM 20%',
                                    'Net to employee',
                                    'Comment',
                                    'Payment status',
                                    'Report status',
                                ] as $header) {
                                    echo '<th>'.e($header).'</th>';
                                }

                                echo '</tr>';

                                foreach ($rows as $dividend) {
                                    $reportStatus = $dividend->dividendPaymentReport?->status?->label() ?? '—';
                                    $paymentStatus = $dividend->status?->label() ?? '—';

                                    echo '<tr>';
                                    echo '<td>'.e((string) ($dividend->date?->format('Y-m-d') ?? '')).'</td>';
                                    echo '<td>'.e((string) ($dividend->employee?->fullName() ?? '')).'</td>';
                                    echo '<td>'.e((string) ($dividend->amount ?? '')).'</td>';
                                    echo '<td>'.e((string) ($dividend->gpm_amount ?? '')).'</td>';
                                    echo '<td>'.e((string) ($dividend->net_amount ?? '')).'</td>';
                                    echo '<td>'.e((string) ($dividend->comment ?? '')).'</td>';
                                    echo '<td>'.e($paymentStatus).'</td>';
                                    echo '<td>'.e($reportStatus).'</td>';
                                    echo '</tr>';
                                }

                                echo '</table>';
                            }, $fileName, [
                                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                            ]);
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'employee',
            'dividendPaymentReport.approvers',
            'dividendPaymentReport.document',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return (auth()->user()?->hasRole('admin') ?? false)
            && ! ($record?->isLocked() ?? false);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    protected static function approverUserOptions(): array
    {
        return User::query()
            ->whereDoesntHave(
                'roles',
                fn (Builder $query): Builder => $query->where('name', 'customer'),
            )
            ->orderBy('name')
            ->orderBy('surname')
            ->get()
            ->mapWithKeys(function (User $user): array {
                $name = trim(($user->name ?? '').' '.($user->surname ?? ''));

                return [
                    $user->id => $name !== ''
                        ? (filled($user->email) ? $name.' ('.$user->email.')' : $name)
                        : (string) ($user->email ?? 'User #'.$user->id),
                ];
            })
            ->all();
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {
        $gross = (float) str_replace(',', '.', (string) ($data['amount'] ?? 0));
        $tax = app(LithuanianDividendCalculator::class)->calculate($gross);

        $data['gpm_amount'] = $tax['gpm'];
        $data['net_amount'] = $tax['net'];

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDividends::route('/'),
            'create' => Pages\CreateDividend::route('/create'),
            'edit' => Pages\EditDividend::route('/{record}/edit'),
        ];
    }
}
