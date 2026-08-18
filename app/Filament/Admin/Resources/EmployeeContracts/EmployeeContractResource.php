<?php

namespace App\Filament\Admin\Resources\EmployeeContracts;

use App\Enums\EmployeeContractStatus;
use App\Filament\Admin\Resources\EmployeeContracts\Pages;
use App\Models\Employee;
use App\Models\EmployeeContract;
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

class EmployeeContractResource extends Resource
{
    protected static ?string $model = EmployeeContract::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Employees contracts';

    protected static ?string $modelLabel = 'Employee contract';

    protected static ?string $pluralModelLabel = 'Employees contracts';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

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
                    ->native(false),

                Forms\Components\DatePicker::make('sign_date')
                    ->label('Sign date')
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('effective_date_from')
                    ->label('Effective date from')
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('valid_to')
                    ->label('Valid to')
                    ->native(false),

                Forms\Components\TextInput::make('base_salary')
                    ->label('Base salary')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix()),

                Forms\Components\TextInput::make('default_bonus')
                    ->label('Default bonus')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->placeholder('Optional'),

                Forms\Components\TextInput::make('state_percentage')
                    ->label('State percentage')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->default(100)
                    ->suffix('%'),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(EmployeeContractStatus::options())
                    ->default(EmployeeContractStatus::Draft->value)
                    ->required()
                    ->native(false),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (EmployeeContract $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('sign_date')
                    ->label('Sign date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_date_from')
                    ->label('Effective from')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_to')
                    ->label('Valid to')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_salary')
                    ->label('Base salary')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_bonus')
                    ->label('Default bonus')
                    ->money(Money::currency())
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('state_percentage')
                    ->label('State %')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (EmployeeContract $record): string => match ($record->status) {
                        EmployeeContractStatus::Draft => 'gray',
                        EmployeeContractStatus::Ready => 'warning',
                        EmployeeContractStatus::Signed => 'success',
                        EmployeeContractStatus::Inactive => 'danger',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (EmployeeContract $record): string => $record->status?->label() ?? '—')
                    ->sortable(),
            ])
            ->defaultSort('effective_date_from', 'desc')
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
            ])
            ->recordActions([
                EmployeeContractSignAction::make(),
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
            'index' => Pages\ListEmployeeContracts::route('/'),
            'create' => Pages\CreateEmployeeContract::route('/create'),
            'edit' => Pages\EditEmployeeContract::route('/{record}/edit'),
        ];
    }
}
