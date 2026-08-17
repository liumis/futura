<?php

namespace App\Filament\Admin\Resources\OvertimeRequests;

use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\OvertimeRequests\Pages;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OvertimeRequestResource extends Resource
{
    protected static ?string $model = OvertimeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Overtime requests';

    protected static ?string $modelLabel = 'Overtime request';

    protected static ?string $pluralModelLabel = 'Overtime requests';

    protected static string|UnitEnum|null $navigationGroup = 'People & contracts';

    protected static ?int $navigationSort = 6;

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
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Select::make('overtime_request_type_id')
                    ->label('Type')
                    ->relationship('overtimeRequestType', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\DatePicker::make('date')
                    ->label('Date')
                    ->required()
                    ->native(false)
                    ->default(now())
                    ->minDate(fn (Get $get, ?OvertimeRequest $record): ?string => static::dateMinBound($get, $record))
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false)
                    ->helperText(fn (?OvertimeRequest $record): ?string => ($record?->isLocked() ?? false)
                        ? 'Confirmed requests are locked. Use cancellation if needed.'
                        : 'Past dates are allowed while the request is not confirmed.'),

                Forms\Components\TextInput::make('hours')
                    ->label('Hours')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->suffix('h')
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(OvertimeRequestStatus::options())
                    ->default(OvertimeRequestStatus::New->value)
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
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false)
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
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false)
                    ->helperText('Optional extra people who must also approve this overtime. They are not involved in cancellation.')
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('extra_approver_status')
                    ->label('Extra approval status')
                    ->content(function (?OvertimeRequest $record): string {
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
                    ->visible(fn (?OvertimeRequest $record): bool => $record !== null && $record->extraApprovers()->exists())
                    ->columnSpanFull(),

                Forms\Components\DateTimePicker::make('confirmed_at')
                    ->label('Date of confirmation')
                    ->seconds(false)
                    ->native(false)
                    ->disabled()
                    ->dehydrated(fn (Get $get): bool => static::isConfirmedStatus($get))
                    ->helperText('Filled automatically when the main confirmer confirms.'),

                Forms\Components\Textarea::make('comment')
                    ->rows(4)
                    ->columnSpanFull()
                    ->disabled(fn (?OvertimeRequest $record): bool => $record?->isLocked() ?? false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (OvertimeRequest $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('overtimeRequestType.name')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('hours')
                    ->label('Hours')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' h')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (OvertimeRequest $record): string => match ($record->status) {
                        OvertimeRequestStatus::New => 'gray',
                        OvertimeRequestStatus::Confirmed => 'success',
                        OvertimeRequestStatus::CancellationPending => 'warning',
                        OvertimeRequestStatus::Canceled => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?OvertimeRequestStatus $state): string => $state?->label() ?? '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('confirmedBy.fullName')
                    ->label('Confirmed by')
                    ->getStateUsing(fn (OvertimeRequest $record): string => $record->confirmedBy?->fullName()
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
                    ->getStateUsing(fn (OvertimeRequest $record): string => $record->extraApprovalSummary())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('comment')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
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
                    ->options(OvertimeRequestStatus::options()),
            ])
            ->recordActions([
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
        return parent::getEloquentQuery()->with(['employee', 'overtimeRequestType', 'confirmedBy', 'extraApprovers']);
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
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOvertimeRequests::route('/'),
            'create' => Pages\CreateOvertimeRequest::route('/create'),
            'edit' => Pages\EditOvertimeRequest::route('/{record}/edit'),
        ];
    }

    protected static function isConfirmedStatus(Get $get): bool
    {
        $status = $get('status');

        return in_array($status, [
            OvertimeRequestStatus::Confirmed->value,
            OvertimeRequestStatus::CancellationPending->value,
            OvertimeRequestStatus::Canceled->value,
        ], true);
    }

    protected static function dateMinBound(Get $get, ?OvertimeRequest $record = null): ?string
    {
        if ($record?->isLocked() || static::isConfirmedStatus($get)) {
            return now()->toDateString();
        }

        return null;
    }
}
