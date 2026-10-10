<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\LeaveRequestType;
use App\Services\ParentalLeaveCalculator;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

final class ParentalLeaveRequestGuard
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertAllowed(array $data, ?int $ignoreLeaveId = null): void
    {
        $typeId = (int) ($data['leave_request_type_id'] ?? 0);
        $type = $typeId > 0 ? LeaveRequestType::query()->find($typeId) : null;

        if (! ParentalLeaveCalculator::isParentalType($type)) {
            return;
        }

        $employeeId = (int) ($data['employee_id'] ?? 0);
        $employee = $employeeId > 0
            ? Employee::query()->with('children')->find($employeeId)
            : null;

        if ($employee === null) {
            Notification::make()
                ->title('Select an employee')
                ->warning()
                ->send();

            throw new Halt;
        }

        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;

        if (blank($dateFrom) || blank($dateTo)) {
            Notification::make()
                ->title('Set leave dates')
                ->warning()
                ->send();

            throw new Halt;
        }

        $result = ParentalLeaveCalculator::validateRequest(
            $employee,
            (string) $dateFrom,
            (string) $dateTo,
            $ignoreLeaveId,
        );

        if ($result['ok']) {
            return;
        }

        Notification::make()
            ->title('Tėvadienis / Mamadienis not allowed')
            ->body($result['message'])
            ->danger()
            ->send();

        throw new Halt;
    }
}
