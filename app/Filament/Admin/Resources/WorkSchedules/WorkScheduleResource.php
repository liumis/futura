<?php

namespace App\Filament\Admin\Resources\WorkSchedules;

use App\Filament\Admin\Resources\WorkSchedules\Pages;
use App\Models\Employee;
use App\Models\LtHoliday;
use App\Models\WorkSchedule;
use App\Services\WorkScheduleGenerator;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class WorkScheduleResource extends Resource
{
    protected static ?string $model = WorkSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Work schedule';

    protected static ?string $modelLabel = 'Work schedule';

    protected static ?string $pluralModelLabel = 'Work schedules';

    protected static string|UnitEnum|null $navigationGroup = 'People & contracts';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
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
                            ->disabled(fn (?WorkSchedule $record): bool => $record !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, string $operation): void {
                                if ($operation !== 'create') {
                                    return;
                                }

                                self::refreshScheduleEntries($get, $set);
                            }),

                        Forms\Components\Select::make('year')
                            ->options(fn (): array => self::yearOptions())
                            ->default((int) now()->year)
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled(fn (?WorkSchedule $record): bool => $record !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, string $operation): void {
                                if ($operation !== 'create') {
                                    return;
                                }

                                self::refreshScheduleEntries($get, $set);
                            }),

                        Forms\Components\Select::make('month')
                            ->options(fn (): array => self::monthOptions())
                            ->default((int) now()->month)
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled(fn (?WorkSchedule $record): bool => $record !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, string $operation): void {
                                if ($operation !== 'create') {
                                    return;
                                }

                                self::refreshScheduleEntries($get, $set);
                            }),
                    ]),

                Forms\Components\Placeholder::make('default_hours_info')
                    ->label('Hours per working day')
                    ->content(function (Get $get): string {
                        $employee = Employee::query()->find($get('employee_id'));

                        if ($employee === null) {
                            return 'Select an employee to calculate default hours.';
                        }

                        $hours = WorkScheduleGenerator::defaultHoursFor($employee);
                        $percentage = number_format((float) $employee->working_time_percentage, 0);

                        return sprintf(
                            '%s h (8 h × %s%% working time)',
                            number_format($hours, 2),
                            $percentage,
                        );
                    })
                    ->visible(fn (Get $get): bool => filled($get('employee_id'))),

                Forms\Components\Placeholder::make('hours_summary')
                    ->label('Month totals')
                    ->content(function (Get $get): string {
                        $entries = $get('schedule_entries');

                        if (! is_array($entries) || $entries === []) {
                            return 'No timetable loaded yet.';
                        }

                        $planned = 0.0;
                        $actual = 0.0;
                        $plannedDays = 0;
                        $actualDays = 0;

                        foreach ($entries as $entry) {
                            $planHours = (float) ($entry['hours'] ?? 0);
                            $actHours = (float) ($entry['actual_hours'] ?? 0);
                            $planned += $planHours;
                            $actual += $actHours;

                            if ($planHours > 0) {
                                $plannedDays++;
                            }

                            if ($actHours > 0) {
                                $actualDays++;
                            }
                        }

                        return sprintf(
                            'Plan: %s h (%d days) · Actual: %s h (%d days) · Diff: %s h',
                            number_format($planned, 2),
                            $plannedDays,
                            number_format($actual, 2),
                            $actualDays,
                            number_format($actual - $planned, 2),
                        );
                    })
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit'
                        && is_array($get('schedule_entries'))
                        && $get('schedule_entries') !== []),

                Section::make('Calendar')
                    ->description(fn (string $operation): string => $operation === 'edit'
                        ? 'Edit planned and actual hours. Yellow rows mean plan and actual differ. Use “Copy plan → actual” to reset actuals.'
                        : 'Working days are filled automatically from the month, weekends, and LT holidays. Hours use the employee working time percentage.')
                    ->schema([
                        View::make('filament.admin.components.work-schedule-weekday-toggles')
                            ->visible(fn (Get $get): bool => is_array($get('schedule_entries')) && $get('schedule_entries') !== [])
                            ->viewData(fn (Get $get): array => [
                                'activeWeekdays' => self::activeWeekdaysFromEntries(
                                    is_array($get('schedule_entries')) ? $get('schedule_entries') : [],
                                ),
                            ]),

                        Forms\Components\Repeater::make('schedule_entries')
                            ->label('')
                            ->schema([
                                Forms\Components\Hidden::make('work_date'),

                                Grid::make(4)
                                    ->extraAttributes(function (Get $get): array {
                                        $date = $get('work_date');
                                        $isNotWorkingDay = (bool) $get('is_not_working_day');
                                        $isWeekend = filled($date) && Carbon::parse($date)->isWeekend();
                                        $plan = (float) ($get('hours') ?? 0);
                                        $actual = (float) ($get('actual_hours') ?? 0);
                                        $classes = [];

                                        if ($isNotWorkingDay || $isWeekend) {
                                            $classes[] = 'fi-work-schedule-weekend';
                                        }

                                        if (abs($plan - $actual) > 0.001) {
                                            $classes[] = 'fi-work-schedule-actual-mismatch';
                                        }

                                        return $classes !== [] ? ['class' => implode(' ', $classes)] : [];
                                    })
                                    ->schema([
                                        Forms\Components\Placeholder::make('date_label')
                                            ->label('Date')
                                            ->content(function (Get $get): string {
                                                $date = $get('work_date');

                                                if (blank($date)) {
                                                    return '—';
                                                }

                                                $carbon = Carbon::parse($date);
                                                $label = $carbon->format('l, Y-m-d');
                                                $holidayName = LtHoliday::nameForDate($carbon);

                                                if ($holidayName !== null) {
                                                    $label .= ' — '.$holidayName;
                                                }

                                                return $label;
                                            }),

                                        Forms\Components\Checkbox::make('is_not_working_day')
                                            ->label('Not working day')
                                            ->live()
                                            ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                                if ($state) {
                                                    $set('hours', 0);
                                                    $set('actual_hours', 0);

                                                    return;
                                                }

                                                if ((float) ($get('hours') ?? 0) !== 0.0) {
                                                    return;
                                                }

                                                $employee = Employee::query()->find($get('../../employee_id'));

                                                if ($employee === null) {
                                                    return;
                                                }

                                                $hours = WorkScheduleGenerator::defaultHoursFor($employee);
                                                $set('hours', $hours);
                                                $set('actual_hours', $hours);
                                            }),

                                        Forms\Components\TextInput::make('hours')
                                            ->label('Plan hours')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.25)
                                            ->suffix('h')
                                            ->required()
                                            ->default(0)
                                            ->disabled(fn (Get $get): bool => (bool) $get('is_not_working_day'))
                                            ->dehydrated(),

                                        Forms\Components\TextInput::make('actual_hours')
                                            ->label('Actual hours')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.25)
                                            ->suffix('h')
                                            ->required()
                                            ->default(0)
                                            ->dehydrated()
                                            ->disabled(fn (Get $get): bool => (bool) $get('is_not_working_day'))
                                            ->visible(fn (string $operation): bool => $operation === 'edit')
                                            ->helperText(function (Get $get): ?string {
                                                $plan = (float) ($get('hours') ?? 0);
                                                $actual = (float) ($get('actual_hours') ?? 0);

                                                if (abs($plan - $actual) <= 0.001) {
                                                    return null;
                                                }

                                                return 'Diff: '.number_format($actual - $plan, 2).' h';
                                            }),
                                    ]),
                            ])
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull()
                            ->live(onBlur: true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (WorkSchedule $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('month_label')
                    ->label('Month')
                    ->getStateUsing(fn (WorkSchedule $record): string => $record->monthLabel())
                    ->sortable(['year', 'month']),

                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Days')
                    ->sortable()
                    ->tooltip('Total days in the timetable'),

                Tables\Columns\TextColumn::make('working_entries_count')
                    ->label('Working days (plan)')
                    ->sortable()
                    ->tooltip('Days with planned hours greater than 0'),

                Tables\Columns\TextColumn::make('actual_working_entries_count')
                    ->label('Working days (actual)')
                    ->sortable()
                    ->tooltip('Days with actual hours greater than 0'),

                Tables\Columns\TextColumn::make('entries_sum_hours')
                    ->label('Monthly hours (plan)')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2).' h')
                    ->sortable(),

                Tables\Columns\TextColumn::make('entries_sum_actual_hours')
                    ->label('Monthly hours (actual)')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2).' h')
                    ->sortable(),
            ])
            ->defaultSort('year', 'desc')
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
        return parent::getEloquentQuery()
            ->with('employee')
            ->withSum('entries', 'hours')
            ->withSum('entries as entries_sum_actual_hours', 'actual_hours')
            ->withCount([
                'entries',
                'entries as working_entries_count' => fn (Builder $query): Builder => $query
                    ->where('hours', '>', 0),
                'entries as actual_working_entries_count' => fn (Builder $query): Builder => $query
                    ->where('actual_hours', '>', 0),
            ])
            ->orderByDesc('year')
            ->orderByDesc('month');
    }

    public static function refreshScheduleEntries(Get $get, Set $set): void
    {
        $set('schedule_entries', self::generatedScheduleEntries(
            employeeId: $get('employee_id'),
            year: $get('year'),
            month: $get('month'),
        ));
    }

    /**
     * @return list<array{work_date: string, hours: float, actual_hours: float, is_not_working_day: bool}>
     */
    public static function generatedScheduleEntries(mixed $employeeId, mixed $year, mixed $month): array
    {
        if (blank($employeeId) || blank($year) || blank($month)) {
            return [];
        }

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return [];
        }

        return WorkScheduleGenerator::forEmployeeMonth($employee, (int) $year, (int) $month);
    }

    /**
     * ISO weekdays (1=Mon … 7=Sun) that currently have at least one working day with hours.
     *
     * @param  list<array{work_date?: string, hours?: mixed, is_not_working_day?: mixed}>  $entries
     * @return array<int, bool>
     */
    public static function activeWeekdaysFromEntries(array $entries): array
    {
        $active = array_fill(1, 7, false);

        foreach ($entries as $entry) {
            if (blank($entry['work_date'] ?? null)) {
                continue;
            }

            if ((bool) ($entry['is_not_working_day'] ?? false) || (float) ($entry['hours'] ?? 0) <= 0) {
                continue;
            }

            $isoDay = Carbon::parse((string) $entry['work_date'])->dayOfWeekIso;
            $active[$isoDay] = true;
        }

        return $active;
    }

    /**
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn (int $month): array => [
                $month => Carbon::create(null, $month, 1)->format('F'),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function yearOptions(): array
    {
        $currentYear = (int) now()->year;

        return collect(range($currentYear - 2, $currentYear + 3))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
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
            'index' => Pages\ListWorkSchedules::route('/'),
            'create' => Pages\CreateWorkSchedule::route('/create'),
            'edit' => Pages\EditWorkSchedule::route('/{record}/edit'),
        ];
    }
}
