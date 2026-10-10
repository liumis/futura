<?php

namespace App\Filament\Admin\Support;

use App\Filament\Admin\Pages\MonthlyPayment;
use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
use App\Filament\Admin\Resources\EmployeeOneTimePayments\EmployeeOneTimePaymentResource;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\BusinessTripRequest;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeeOneTimePayment;
use App\Models\LeaveRequest;
use App\Support\Money;
use Illuminate\Support\Collection;

final class EmployeePaymentsList
{
    /**
     * @return list<array{
     *     type: string,
     *     type_label: string,
     *     date: string,
     *     period: ?string,
     *     amount: float,
     *     amount_label: string,
     *     status: string,
     *     detail: ?string,
     *     url: ?string
     * }>
     */
    public static function rowsFor(Employee $employee): array
    {
        $employee->loadMissing([
            'monthlyPayments',
            'oneTimePayments',
            'businessTripRequests',
            'leaveRequests.leaveRequestType',
        ]);

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect();

        foreach ($employee->monthlyPayments as $payment) {
            /** @var EmployeeMonthlyPayment $payment */
            $date = optional($payment->payment_date)->format('Y-m-d') ?? '—';
            $status = $payment->is_paid
                ? 'Paid'
                : ($payment->status?->label() ?? '—');
            $detail = collect([
                filled($payment->bonus_payment) && (float) $payment->bonus_payment > 0.004
                    ? 'bonus '.Money::format((float) $payment->bonus_payment)
                    : null,
                filled($payment->comment) ? (string) $payment->comment : null,
            ])->filter()->implode(' · ');

            $rows->push([
                'type' => 'du',
                'type_label' => 'DU / salary',
                'date' => $date,
                'sort' => $date,
                'period' => optional($payment->payment_date)->format('Y-m'),
                'amount' => (float) ($payment->net_amount ?? 0),
                'amount_label' => Money::format((float) ($payment->net_amount ?? 0)),
                'status' => $status,
                'detail' => $detail !== '' ? $detail : null,
                'url' => MonthlyPayment::getUrl(),
            ]);
        }

        foreach ($employee->oneTimePayments as $payment) {
            /** @var EmployeeOneTimePayment $payment */
            $date = optional($payment->date)->format('Y-m-d') ?? '—';

            $rows->push([
                'type' => 'one_time',
                'type_label' => 'One-time',
                'date' => $date,
                'sort' => $date,
                'period' => null,
                'amount' => (float) ($payment->amount ?? 0),
                'amount_label' => Money::format((float) ($payment->amount ?? 0)),
                'status' => '—',
                'detail' => null,
                'url' => EmployeeOneTimePaymentResource::getUrl('edit', ['record' => $payment]),
            ]);
        }

        foreach ($employee->businessTripRequests as $trip) {
            /** @var BusinessTripRequest $trip */
            $date = optional($trip->date_from)->format('Y-m-d') ?? '—';
            $period = trim(implode(' → ', array_filter([
                optional($trip->date_from)->format('Y-m-d'),
                optional($trip->date_to)->format('Y-m-d'),
            ])));
            $amount = (float) ($trip->payable_amount ?? $trip->amount ?? 0);
            $detail = collect([
                filled($trip->country_name) ? (string) $trip->country_name : null,
                filled($trip->purpose) ? (string) $trip->purpose : null,
            ])->filter()->implode(' · ');

            $rows->push([
                'type' => 'business_trip',
                'type_label' => 'Business trip',
                'date' => $date,
                'sort' => $date,
                'period' => $period !== '' ? $period : null,
                'amount' => $amount,
                'amount_label' => Money::format($amount),
                'status' => $trip->payment_status?->label() ?? '—',
                'detail' => $detail !== '' ? $detail : null,
                'url' => BusinessTripRequestResource::getUrl('edit', ['record' => $trip]),
            ]);
        }

        foreach ($employee->leaveRequests as $leave) {
            /** @var LeaveRequest $leave */
            $gross = round((float) ($leave->payment_gross ?? 0), 2);
            if ($gross < 0.005) {
                continue;
            }

            $date = optional($leave->date_from)->format('Y-m-d') ?? '—';
            $period = trim(implode(' → ', array_filter([
                optional($leave->date_from)->format('Y-m-d'),
                optional($leave->date_to)->format('Y-m-d'),
            ])));

            $rows->push([
                'type' => 'leave',
                'type_label' => 'Leave pay',
                'date' => $date,
                'sort' => $date,
                'period' => $period !== '' ? $period : null,
                'amount' => $gross,
                'amount_label' => Money::format($gross),
                'status' => $leave->status?->label() ?? '—',
                'detail' => $leave->leaveRequestType?->name,
                'url' => LeaveRequestResource::getUrl('edit', ['record' => $leave]),
            ]);
        }

        return $rows
            ->sortByDesc(fn (array $row): string => (string) ($row['sort'] ?? ''))
            ->values()
            ->map(function (array $row): array {
                unset($row['sort']);

                return $row;
            })
            ->all();
    }
}
