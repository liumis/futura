<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Enums\EmployeeNpdType;
use App\Enums\EmployeeStatus;
use App\Filament\Admin\Pages\AbsenceCalendar;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractEndAction;
use App\Filament\Admin\Support\EmployeePaymentsList;
use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $pluralModelLabel = 'Employees';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

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

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(EmployeeStatus::options())
                    ->default(EmployeeStatus::Active->value)
                    ->required()
                    ->native(false),

                Forms\Components\Select::make('user_id')
                    ->label('Login user')
                    ->relationship('user', 'email')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => filled(trim($record->fullName()))
                        ? $record->fullName().' ('.$record->email.')'
                        : (string) ($record->email ?? ''))
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->nullable()
                    ->native(false)
                    ->helperText('Link this employee to a system user so they can receive confirmation notifications.'),

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

                Forms\Components\Checkbox::make('is_general_manager')
                    ->label('General manager')
                    ->default(false),

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

                Section::make('Children')
                    ->description('Birthdates only. Used for Tėvadienis / Mamadienis (DK 138§3): children under 12, or disabled under 18.')
                    ->schema([
                        Forms\Components\Repeater::make('children')
                            ->relationship()
                            ->label('')
                            ->table([
                                TableColumn::make('Birthdate'),
                                TableColumn::make('Disability (until 18)'),
                            ])
                            ->schema([
                                Forms\Components\DatePicker::make('birthdate')
                                    ->required()
                                    ->native(false)
                                    ->hiddenLabel(),
                                Forms\Components\Toggle::make('has_disability')
                                    ->default(false)
                                    ->inline(false)
                                    ->hiddenLabel(),
                            ])
                            ->addActionLabel('Add child')
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof EmployeeStatus
                        ? $state->label()
                        : EmployeeStatus::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->color(fn ($state): string => ($state instanceof EmployeeStatus
                        ? $state
                        : EmployeeStatus::tryFrom((string) $state)) === EmployeeStatus::Active
                        ? 'success'
                        : 'gray')
                    ->url(function (Employee $record): ?string {
                        if ($record->status !== EmployeeStatus::Inactive) {
                            return null;
                        }

                        return $record->displayedTerminationDocumentUrl();
                    })
                    ->openUrlInNewTab()
                    ->tooltip(function (Employee $record): ?string {
                        if ($record->status !== EmployeeStatus::Inactive) {
                            return null;
                        }

                        $date = $record->displayedTerminationDate();
                        $hasDoc = $record->displayedTerminationDocumentUrl() !== null;

                        if ($hasDoc && $date !== null) {
                            return 'Open termination document · ended '.$date;
                        }

                        if ($hasDoc) {
                            return 'Open termination document';
                        }

                        return $date !== null ? 'Terminated '.$date.' (no document linked)' : null;
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('termination_date')
                    ->label('Termination date')
                    ->getStateUsing(fn (Employee $record): ?string => $record->displayedTerminationDate())
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('contract_end_date', $direction);
                    }),

                Tables\Columns\TextColumn::make('termination_document')
                    ->label('Termination document')
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->getStateUsing(function (Employee $record): string {
                        $document = $record->displayedTerminationDocument();
                        if ($document === null) {
                            return '—';
                        }

                        $links = $document->attachedFileLinks();
                        if ($links === []) {
                            $url = $document->displayFileUrl();
                            $label = e($document->name ?: 'Termination document');

                            return $url
                                ? '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>'
                                : $label;
                        }

                        $items = [];
                        foreach ($links as $file) {
                            $name = e((string) ($file['name'] ?? 'file'));
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url !== null
                                ? '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a>'
                                : $name;
                        }

                        return implode('<br>', $items);
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Login user')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ViewColumn::make('confirmations')
                    ->label('Confirmations')
                    ->view('filament.admin.components.employee-confirmations')
                    ->extraCellAttributes(['class' => 'ss-employee-confirmations-cell'])
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_general_manager')
                    ->label('General manager')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ViewColumn::make('related_links')
                    ->label('Related')
                    ->view('filament.admin.components.employee-related-links')
                    ->extraCellAttributes(['class' => 'ss-employee-related-cell']),
            ])
            ->recordClasses(fn (Employee $record): ?string => $record->status === EmployeeStatus::Inactive
                ? 'ss-employee-row--inactive'
                : null)
            ->recordActions([
                Action::make('calendar')
                    ->label('Calendar')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Calendar')
                    ->url(fn (Employee $record): string => AbsenceCalendar::getUrl([
                        'employee_id' => $record->getKey(),
                    ]))
                    ->visible(fn (): bool => AbsenceCalendar::canAccess()),
                Action::make('payments')
                    ->label('Payments')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Payments')
                    ->modalHeading(fn (Employee $record): string => 'Payments — '.$record->fullName())
                    ->modalContent(fn (Employee $record): Htmlable => new HtmlString(
                        view('filament.admin.components.employee-payments-modal', [
                            'rows' => EmployeePaymentsList::rowsFor($record),
                        ])->render()
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl'),
                EmployeeContractEndAction::make()
                    ->iconButton()
                    ->tooltip('End contract'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Edit'),
            ])
            ->recordActionsAlignment('start')
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
