<?php

namespace App\Filament\Admin\Support;

use App\Filament\Admin\Pages\AbsenceCalendar;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Filament\Admin\Resources\WorkSchedules\WorkScheduleResource;
use App\Models\Employee;

final class EmployeeRelatedLinks
{
    /**
     * @return list<array{
     *     label: string,
     *     links: list<array{label: string, variant: string, url: string, title: string}>
     * }>
     */
    public static function groupsFor(Employee $employee): array
    {
        $employeeId = $employee->getKey();
        $name = $employee->fullName();

        $employeeFilter = [
            'filters' => [
                'employee_id' => [
                    'value' => $employeeId,
                ],
            ],
        ];

        return [
            [
                'label' => 'Contracts',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => EmployeeContractResource::getUrl('index', $employeeFilter),
                        'title' => "View contracts for {$name}",
                    ],
                    [
                        'label' => 'Add',
                        'variant' => 'add',
                        'url' => EmployeeContractResource::getUrl('create', ['employee_id' => $employeeId]),
                        'title' => "Add contract for {$name}",
                    ],
                ],
            ],
            [
                'label' => 'Schedule',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => WorkScheduleResource::getUrl('index', $employeeFilter),
                        'title' => "View work schedules for {$name}",
                    ],
                    [
                        'label' => 'Add',
                        'variant' => 'add',
                        'url' => WorkScheduleResource::getUrl('create', ['employee_id' => $employeeId]),
                        'title' => "Add work schedule for {$name}",
                    ],
                ],
            ],
            [
                'label' => 'Leave requests',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => LeaveRequestResource::getUrl('index', $employeeFilter),
                        'title' => "View leave requests for {$name}",
                    ],
                    [
                        'label' => 'Add',
                        'variant' => 'add',
                        'url' => LeaveRequestResource::getUrl('create', ['employee_id' => $employeeId]),
                        'title' => "Add leave request for {$name}",
                    ],
                ],
            ],
            [
                'label' => 'Overtime requests',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => OvertimeRequestResource::getUrl('index', $employeeFilter),
                        'title' => "View overtime requests for {$name}",
                    ],
                    [
                        'label' => 'Add',
                        'variant' => 'add',
                        'url' => OvertimeRequestResource::getUrl('create', ['employee_id' => $employeeId]),
                        'title' => "Add overtime request for {$name}",
                    ],
                ],
            ],
            [
                'label' => 'Work calendar',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => AbsenceCalendar::getUrl(['employee_id' => $employeeId]),
                        'title' => "Open work calendar for {$name}",
                    ],
                ],
            ],
        ];
    }
}
