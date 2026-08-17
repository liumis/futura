<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Enums\EmployeeNpdType;
use App\Filament\Admin\Resources\Employees\Pages;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'People';

    protected static ?string $modelLabel = 'Person';

    protected static ?string $pluralModelLabel = 'People';

    protected static string|UnitEnum|null $navigationGroup = 'People & contracts';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('surname')
                    ->label('Surname')
                    ->required()
                    ->maxLength(255),

                Forms\Components\DatePicker::make('birthdate')
                    ->label('Birthdate'),

                Forms\Components\TextInput::make('position')
                    ->maxLength(255),

                Forms\Components\TextInput::make('bank_account')
                    ->label('Bank account')
                    ->maxLength(255),

                Forms\Components\DatePicker::make('contract_signed_date')
                    ->label('Contract signed date')
                    ->native(false),

                Forms\Components\DatePicker::make('contract_end_date')
                    ->label('Contract end date')
                    ->native(false),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(50),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),

                Forms\Components\TextInput::make('working_time_percentage')
                    ->label('Working time')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->default(100)
                    ->suffix('%')
                    ->required(),

                Forms\Components\TextInput::make('shareholder_percentage')
                    ->label('Shareholder')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%'),

                Section::make('VMI')
                    ->description('Payroll tax flags used for NPD / GPM and II pillar when calculating net pay.')
                    ->schema([
                        Forms\Components\Select::make('npd_type')
                            ->label('NPD')
                            ->options(EmployeeNpdType::options())
                            ->default(EmployeeNpdType::Standard->value)
                            ->required()
                            ->native(false)
                            ->helperText('Usual VMI groups: standard formula, or disability / participation 0–25% (€1,127) and 30–55% (€1,057).'),

                        Forms\Components\Toggle::make('second_pillar_enrolled')
                            ->label('II pillar (Sodra)')
                            ->live()
                            ->default(false),

                        Forms\Components\Select::make('second_pillar_rate')
                            ->label('II pillar rate')
                            ->options([
                                '0.0240' => '2.4%',
                                '0.0300' => '3%',
                            ])
                            ->default('0.0300')
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('second_pillar_enrolled'))
                            ->required(fn (Get $get): bool => (bool) $get('second_pillar_enrolled'))
                            ->dehydrated(fn (Get $get): bool => (bool) $get('second_pillar_enrolled'))
                            ->formatStateUsing(function (mixed $state): ?string {
                                if ($state === null || $state === '') {
                                    return null;
                                }

                                return number_format((float) $state, 4, '.', '');
                            }),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('surname')
                    ->label('Surname')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('position')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\ViewColumn::make('related_links')
                    ->label('Related')
                    ->view('filament.admin.components.employee-related-links')
                    ->extraCellAttributes(['class' => 'ss-employee-related-cell']),
            ])
            ->defaultSort('surname')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
