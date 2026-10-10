<?php

namespace App\Filament\Admin\Support;

use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
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
                'label' => 'Leave',
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
                'label' => 'Trips',
                'links' => [
                    [
                        'label' => 'View',
                        'variant' => 'view',
                        'url' => BusinessTripRequestResource::getUrl('index', $employeeFilter),
                        'title' => "View business trips for {$name}",
                    ],
                    [
                        'label' => 'Add',
                        'variant' => 'add',
                        'url' => BusinessTripRequestResource::getUrl('create', ['employee_id' => $employeeId]),
                        'title' => "Add business trip for {$name}",
                    ],
                ],
            ],
            [
                'label' => 'Overtime',
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
        ];
    }
}
