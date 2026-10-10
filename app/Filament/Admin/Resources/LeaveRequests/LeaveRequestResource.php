<?php

namespace App\Filament\Admin\Resources\LeaveRequests;

use App\Enums\LeaveRequestStatus;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\User;
use App\Services\LithuanianLeavePaymentCalculator;
use App\Services\ParentalLeaveCalculator;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Leave requests';

    protected static ?string $modelLabel = 'Leave request';

    protected static ?string $pluralModelLabel = 'Leave requests';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 4;

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
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Select::make('leave_request_type_id')
                    ->label('Type')
                    ->relationship('leaveRequestType', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Placeholder::make('parental_leave_balance')
                    ->label('Tėvadienis / Mamadienis balance')
                    ->content(function (Get $get, ?LeaveRequest $record): string {
                        $typeId = (int) ($get('leave_request_type_id') ?? 0);
                        $type = $typeId > 0 ? LeaveRequestType::query()->find($typeId) : null;

                        if (! ParentalLeaveCalculator::isParentalType($type)) {
                            return '—';
                        }

                        $employeeId = (int) ($get('employee_id') ?? 0);
                        $employee = $employeeId > 0
                            ? Employee::query()->with('children')->find($employeeId)
                            : null;

                        if ($employee === null) {
                            return 'Select an employee to see entitlement.';
                        }

                        $asOf = filled($get('date_from'))
                            ? (string) $get('date_from')
                            : Carbon::today()->toDateString();

                        $balance = ParentalLeaveCalculator::calculate(
                            $employee,
                            $asOf,
                            $record?->getKey() ? (int) $record->getKey() : null,
                        );

                        if (! $balance['ok']) {
                            return (string) $balance['message'];
                        }

                        return sprintf(
                            '%s | Children under 12: %d%s | Period %s (%s – %s): %.0f entitled, %.0f used, %.0f available.',
                            $balance['rule_label'],
                            $balance['children_under_12'],
                            $balance['children_disabled_under_18'] > 0
                                ? ', disabled under 18: '.$balance['children_disabled_under_18']
                                : '',
                            $balance['period_label'],
                            $balance['period_from'],
                            $balance['period_to'],
                            $balance['entitlement_days'],
                            $balance['used_days'],
                            $balance['available_days'],
                        );
                    })
                    ->visible(function (Get $get): bool {
                        $typeId = (int) ($get('leave_request_type_id') ?? 0);
                        if ($typeId <= 0) {
                            return false;
                        }

                        $type = LeaveRequestType::query()->find($typeId);

                        return ParentalLeaveCalculator::isParentalType($type);
                    })
                    ->columnSpanFull(),

                Forms\Components\DatePicker::make('date_from')
                    ->label('Date from')
                    ->required()
                    ->native(false)
                    ->live()
                    ->minDate(fn (Get $get, ?LeaveRequest $record): ?string => static::dateMinBound($get, $record))
                    ->maxDate(fn (Get $get): ?string => $get('date_to'))
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false)
                    ->helperText(fn (?LeaveRequest $record): ?string => ($record?->isLocked() ?? false)
                        ? 'Confirmed requests are locked. Use cancellation if needed.'
                        : 'Past dates are allowed while the request is not confirmed.'),

                Forms\Components\DatePicker::make('date_to')
                    ->label('Date to')
                    ->required()
                    ->native(false)
                    ->live()
                    ->minDate(function (Get $get, ?LeaveRequest $record): ?string {
                        $statusMin = static::dateMinBound($get, $record);
                        $from = $get('date_from');

                        if (filled($from) && filled($statusMin)) {
                            return max((string) $from, (string) $statusMin);
                        }

                        return filled($from) ? (string) $from : $statusMin;
                    })
                    ->rule('after_or_equal:date_from')
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\TextInput::make('payment_gross')
                    ->label('Payment (gross)')
                    ->numeric()
                    ->prefix(Money::prefix())
                    ->disabled()
                    ->dehydrated()
                    ->helperText('Filled by Calculate using Lithuanian average wage (VDU). Recalculate anytime from the header action.'),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(LeaveRequestStatus::options())
                    ->default(LeaveRequestStatus::New->value)
                    ->required()
                    ->native(false)
                    ->disabled()
                    ->dehydrated(),

                Forms\Components\Select::make('confirmed_by')
                    ->label('Confirmed by')
                    ->options(fn (): array => User::query()
                        ->role('admin')
                        ->orderBy('name')
                        ->orderBy('surname')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [
                            $user->getKey() => $user->fullName() !== ''
                                ? $user->fullName()
                                : (string) ($user->email ?? $user->getKey()),
                        ])
                        ->all())
                    ->native(false)
                    ->placeholder('Select person')
                    ->required()
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false)
                    ->helperText('Main confirmer. If you select yourself, your confirmation is recorded automatically. Extra approvers still need to approve.'),

                Forms\Components\Select::make('extraApprovers')
                    ->label('Extra approvers')
                    ->relationship(
                        'extraApprovers',
                        'name',
                        fn (Builder $query) => $query->role('admin')->orderBy('name')->orderBy('surname'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (User $user): string => $user->fullName() !== ''
                            ? $user->fullName()
                            : (string) ($user->email ?? $user->getKey()),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable(['name', 'surname', 'email'])
                    ->native(false)
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false)
                    ->helperText('Optional extra people who must also approve this leave. They are not involved in cancellation.')
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('extra_approver_status')
                    ->label('Extra approval status')
                    ->content(function (?LeaveRequest $record): string {
                        if ($record === null) {
                            return '—';
                        }

                        $record->loadMissing('extraApprovers');

                        if ($record->extraApprovers->isEmpty()) {
                            return 'No extra approvers.';
                        }

                        return $record->extraApprovers
                            ->map(function (User $approver): string {
                                $name = $approver->fullName() !== ''
                                    ? $approver->fullName()
                                    : (string) ($approver->email ?? $approver->getKey());
                                $done = filled($approver->pivot?->approved_at);

                                return $name.($done ? ' (approved)' : ' (pending)');
                            })
                            ->implode(', ');
                    })
                    ->visible(fn (?LeaveRequest $record): bool => $record !== null && $record->extraApprovers()->exists())
                    ->columnSpanFull(),

                Forms\Components\DateTimePicker::make('confirmed_at')
                    ->label('Date of confirmation')
                    ->seconds(false)
                    ->native(false)
                    ->disabled()
                    ->dehydrated(fn (Get $get): bool => static::isConfirmedStatus($get))
                    ->helperText('Filled automatically when the main confirmer confirms.'),

                Forms\Components\Placeholder::make('prasymas_document_link')
                    ->label('Prašymas')
                    ->content(function (?LeaveRequest $record): HtmlString|string {
                        if ($record === null || ! filled($record->document_id)) {
                            return 'Created when the leave request is saved.';
                        }

                        $document = $record->document;
                        if ($document === null) {
                            return '—';
                        }

                        $url = $document->displayFileUrl()
                            ?: DocumentResource::getUrl('edit', ['record' => $document]);

                        return new HtmlString(
                            '<a href="'.e($url).'" target="_blank" class="text-primary-600 underline dark:text-primary-400">Open Prašymas</a>'
                        );
                    })
                    ->visible(fn (?LeaveRequest $record): bool => $record !== null),

                Forms\Components\Placeholder::make('isakymas_document_link')
                    ->label('Įsakymas')
                    ->content(function (?LeaveRequest $record): HtmlString|string {
                        if ($record === null) {
                            return '—';
                        }

                        if (! filled($record->order_document_id)) {
                            return $record->isConfirmed()
                                ? 'Not created yet (or creation failed — check logs).'
                                : 'Created automatically when the leave request is confirmed.';
                        }

                        $document = $record->orderDocument;
                        if ($document === null) {
                            return '—';
                        }

                        $url = $document->displayFileUrl()
                            ?: DocumentResource::getUrl('edit', ['record' => $document]);

                        $approved = $document->flag_approved ? ' (approved)' : '';

                        return new HtmlString(
                            '<a href="'.e($url).'" target="_blank" class="text-primary-600 underline dark:text-primary-400">Open Įsakymas'.$approved.'</a>'
                        );
                    })
                    ->visible(fn (?LeaveRequest $record): bool => $record !== null)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('comment')
                    ->rows(4)
                    ->columnSpanFull()
                    ->disabled(fn (?LeaveRequest $record): bool => $record?->isLocked() ?? false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (LeaveRequest $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('leaveRequestType.name')
                    ->label('Type')
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

                Tables\Columns\TextColumn::make('payment_gross')
                    ->label('Payment (gross)')
                    ->money(Money::currency())
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (LeaveRequest $record): string => match ($record->status) {
                        LeaveRequestStatus::New => 'gray',
                        LeaveRequestStatus::Confirmed => 'success',
                        LeaveRequestStatus::CancellationPending => 'warning',
                        LeaveRequestStatus::Canceled => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?LeaveRequestStatus $state): string => $state?->label() ?? '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('confirmedBy.fullName')
                    ->label('Confirmed by')
                    ->getStateUsing(fn (LeaveRequest $record): string => $record->confirmedBy?->fullName()
                        ?: (string) ($record->confirmedBy?->email ?? '—'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmed at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('extra_approvers')
                    ->label('Extra approvers')
                    ->getStateUsing(fn (LeaveRequest $record): string => $record->extraApprovalSummary())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('document_pdf')
                    ->label('Prašymas')
                    ->state(fn (LeaveRequest $record): string => filled($record->document_id) ? 'View' : '—')
                    ->url(function (LeaveRequest $record): ?string {
                        if (! filled($record->document_id)) {
                            return null;
                        }

                        $document = $record->document;
                        if ($document === null) {
                            return null;
                        }

                        return $document->displayFileUrl()
                            ?: DocumentResource::getUrl('edit', ['record' => $document]);
                    })
                    ->openUrlInNewTab()
                    ->color(fn (LeaveRequest $record): ?string => filled($record->document_id) ? 'primary' : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('order_document_pdf')
                    ->label('Įsakymas')
                    ->state(fn (LeaveRequest $record): string => filled($record->order_document_id) ? 'View' : '—')
                    ->url(function (LeaveRequest $record): ?string {
                        if (! filled($record->order_document_id)) {
                            return null;
                        }

                        $document = $record->orderDocument;
                        if ($document === null) {
                            return null;
                        }

                        return $document->displayFileUrl()
                            ?: DocumentResource::getUrl('edit', ['record' => $document]);
                    })
                    ->openUrlInNewTab()
                    ->color(fn (LeaveRequest $record): ?string => filled($record->order_document_id) ? 'primary' : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('comment')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->options(LeaveRequestStatus::options()),
            ])
            ->recordActions([
                Action::make('calculateLeavePayment')
                    ->label('Calculate')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->action(function (LeaveRequest $record): void {
                        $record->loadMissing(['employee', 'leaveRequestType']);

                        $employee = $record->employee;
                        if ($employee === null) {
                            Notification::make()
                                ->title('Employee is missing')
                                ->warning()
                                ->send();

                            return;
                        }

                        if (blank($record->date_from) || blank($record->date_to)) {
                            Notification::make()
                                ->title('Leave dates are missing')
                                ->warning()
                                ->send();

                            return;
                        }

                        $result = LithuanianLeavePaymentCalculator::calculate(
                            $employee,
                            $record->date_from,
                            $record->date_to,
                            $record->leaveRequestType,
                        );

                        if (! $result['ok']) {
                            Notification::make()
                                ->title('Could not calculate payment')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->forceFill([
                            'payment_gross' => $result['gross'],
                        ])->save();

                        Notification::make()
                            ->title('Payment (gross) calculated')
                            ->body($result['message'])
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'leaveRequestType', 'confirmedBy', 'document', 'extraApprovers']);
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
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'edit' => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }

    protected static function isConfirmedStatus(Get $get): bool
    {
        $status = $get('status');

        return in_array($status, [
            LeaveRequestStatus::Confirmed->value,
            LeaveRequestStatus::CancellationPending->value,
            LeaveRequestStatus::Canceled->value,
        ], true);
    }

    protected static function dateMinBound(Get $get, ?LeaveRequest $record = null): ?string
    {
        if ($record?->isLocked() || static::isConfirmedStatus($get)) {
            return now()->toDateString();
        }

        return null;
    }
}
