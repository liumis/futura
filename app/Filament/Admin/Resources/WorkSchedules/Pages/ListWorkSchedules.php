<?php

namespace App\Filament\Admin\Resources\WorkSchedules\Pages;

use App\Filament\Admin\Resources\WorkSchedules\WorkScheduleResource;
use App\Models\Employee;
use App\Services\ActivityLogger;
use App\Services\WorkTimeTimesheetPdfGenerator;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;

class ListWorkSchedules extends ListRecords
{
    protected static string $resource = WorkScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('timesheetPdf')
                ->label('Timesheet PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->modalHeading('Generate timesheet')
                ->modalDescription('Select a month and employees. The PDF follows the Lithuanian monthly working-time register.')
                ->modalSubmitActionLabel('Generate PDF')
                ->modalWidth('lg')
                ->fillForm(fn (): array => [
                    'month' => now()->format('Y-m'),
                    'select_all' => false,
                    'employee_ids' => [],
                ])
                ->form([
                    Select::make('month')
                        ->label('Month')
                        ->options(fn (): array => WorkTimeTimesheetPdfGenerator::monthSelectOptions())
                        ->default(now()->format('Y-m'))
                        ->required()
                        ->native(false)
                        ->searchable(),

                    Checkbox::make('select_all')
                        ->label('Select all employees')
                        ->live()
                        ->afterStateUpdated(function (bool $state, Set $set): void {
                            $set('employee_ids', $state ? self::employeeIds() : []);
                        }),

                    Select::make('employee_ids')
                        ->label('Employees')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->options(fn (): array => self::employeeOptions())
                        ->live()
                        ->afterStateUpdated(function (?array $state, Set $set): void {
                            $selected = collect($state ?? [])->map(fn ($id): int => (int) $id)->all();
                            $all = self::employeeIds();
                            $set('select_all', $all !== [] && count($selected) === count($all));
                        }),
                ])
                ->action(function (array $data) {
                    $monthKey = (string) ($data['month'] ?? '');
                    $parts = explode('-', $monthKey);
                    $year = (int) ($parts[0] ?? 0);
                    $month = (int) ($parts[1] ?? 0);
                    $employeeIds = collect($data['employee_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->unique()
                        ->values()
                        ->all();

                    if ($year < 2000 || $month < 1 || $month > 12 || $employeeIds === []) {
                        Notification::make()
                            ->title('Select a month and at least one employee')
                            ->warning()
                            ->send();

                        return;
                    }

                    $binary = WorkTimeTimesheetPdfGenerator::generate($year, $month, $employeeIds);
                    $filename = sprintf('darbo-laiko-ziniarastis-%04d-%02d.pdf', $year, $month);

                    ActivityLogger::logReportDownloaded('Work time timesheet PDF', 'pdf', null, [
                        'year' => $year,
                        'month' => $month,
                        'employee_ids' => $employeeIds,
                        'employee_count' => count($employeeIds),
                        'file_name' => $filename,
                    ]);

                    return response()->streamDownload(function () use ($binary): void {
                        echo $binary;
                    }, $filename, [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),
            Actions\CreateAction::make(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function employeeOptions(): array
    {
        return Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->fullName(),
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    protected static function employeeIds(): array
    {
        return Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
