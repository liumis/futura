<?php

namespace App\Filament\Admin\Resources\EmployeeOneTimePayments;

use App\Filament\Admin\Resources\EmployeeOneTimePayments\Pages;
use App\Models\Employee;
use App\Models\EmployeeOneTimePayment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeOneTimePaymentResource extends Resource
{
    protected static ?string $model = EmployeeOneTimePayment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Employees one time payments';

    protected static ?string $modelLabel = 'Employee one time payment';

    protected static ?string $pluralModelLabel = 'Employees one time payments';

    protected static string|UnitEnum|null $navigationGroup = 'People & contracts';

    protected static ?int $navigationSort = 7;

    protected static bool $shouldRegisterNavigation = false;

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
                    ->native(false),

                Forms\Components\DatePicker::make('date')
                    ->required()
                    ->native(false),

                Forms\Components\TextInput::make('amount')
                    ->label('Ammount')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix()),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (EmployeeOneTimePayment $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Ammount')
                    ->money(Money::currency())
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
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
        return parent::getEloquentQuery()->with('employee');
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
            'index' => Pages\ListEmployeeOneTimePayments::route('/'),
            'create' => Pages\CreateEmployeeOneTimePayment::route('/create'),
            'edit' => Pages\EditEmployeeOneTimePayment::route('/{record}/edit'),
        ];
    }
}
