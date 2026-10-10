<?php

namespace App\Filament\Admin\Resources\BusinessTripRequests\Pages;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
use App\Filament\Admin\Resources\BusinessTripRequests\Concerns\AttachesBusinessTripDocuments;
use App\Models\BusinessTripDailyAllowance;
use App\Models\BusinessTripRequest;
use App\Models\Employee;
use App\Services\BusinessTripAllowanceCalculator;
use App\Services\BusinessTripAllowanceTaxCalculator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateBusinessTripRequest extends CreateRecord
{
    use AttachesBusinessTripDocuments;

    protected static string $resource = BusinessTripRequestResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [
            'status' => BusinessTripRequestStatus::New->value,
            'payment_status' => BusinessTripPaymentStatus::Unpaid->value,
        ];

        $employeeId = (int) request()->query('employee_id');
        if ($employeeId > 0 && Employee::query()->whereKey($employeeId)->exists()) {
            $data['employee_id'] = $employeeId;
        }

        $dateFrom = (string) request()->query('date_from', '');
        $dateTo = (string) request()->query('date_to', $dateFrom);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $data['date_from'] = $dateFrom;
            $data['date_to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1 ? $dateTo : $dateFrom;
        }

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->extractDocumentUploads($data);
        $data['status'] = BusinessTripRequestStatus::New->value;
        $data['payment_status'] = BusinessTripPaymentStatus::Unpaid->value;

        return $this->withCalculatedAmounts($data);
    }

    protected function afterCreate(): void
    {
        /** @var BusinessTripRequest $record */
        $record = $this->record;
        $this->storeCapturedTripDocuments($record);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCalculatedAmounts(array $data): array
    {
        $allowanceId = $data['business_trip_daily_allowance_id'] ?? null;
        if (filled($allowanceId)) {
            $allowance = BusinessTripDailyAllowance::query()->find((int) $allowanceId);
            if ($allowance !== null) {
                $data['country_name'] = $allowance->country;
                $data['daily_rate'] = (float) $allowance->daily_rate;
            }
        }

        if (! filled($data['daily_rate'] ?? null) || ! filled($data['date_from'] ?? null) || ! filled($data['date_to'] ?? null)) {
            return $data;
        }

        $result = BusinessTripAllowanceCalculator::calculate(
            (float) $data['daily_rate'],
            Carbon::parse((string) $data['date_from']),
            Carbon::parse((string) $data['date_to']),
            filled($data['first_day_hours'] ?? null) ? (float) $data['first_day_hours'] : null,
            filled($data['last_day_hours'] ?? null) ? (float) $data['last_day_hours'] : null,
        );

        $data['calendar_days'] = $result['calendar_days'];
        $data['payable_units'] = $result['payable_units'];
        $data['amount'] = $result['amount'];

        $employeeId = (int) ($data['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            $data['non_taxable_amount'] = $result['amount'];
            $data['taxable_amount'] = 0;
            $data['gpm_amount'] = 0;
            $data['payable_amount'] = $result['amount'];
            $data['is_low_salary_case'] = false;

            return $data;
        }

        $taxMonth = BusinessTripAllowanceTaxCalculator::resolveTaxMonth(
            dateTo: Carbon::parse((string) $data['date_to']),
        );

        $tax = BusinessTripAllowanceTaxCalculator::forRequest(
            (float) $result['amount'],
            $employeeId,
            $taxMonth,
        );

        return array_merge($data, [
            'tax_year' => $tax['tax_year'],
            'tax_month' => $tax['tax_month'],
            'salary_snapshot' => $tax['salary_snapshot'],
            'salary_threshold_snapshot' => $tax['salary_threshold_snapshot'],
            'is_low_salary_case' => $tax['is_low_salary_case'],
            'month_free_cap_snapshot' => $tax['month_free_cap_snapshot'],
            'non_taxable_amount' => $tax['non_taxable_amount'],
            'taxable_amount' => $tax['taxable_amount'],
            'gpm_amount' => $tax['gpm_amount'],
            'payable_amount' => $tax['payable_amount'],
        ]);
    }
}
