<?php

namespace App\Filament\Admin\Resources\BusinessTripRequests\Pages;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
use App\Filament\Admin\Resources\BusinessTripRequests\Concerns\AttachesBusinessTripDocuments;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\BusinessTripDailyAllowance;
use App\Models\BusinessTripRequest;
use App\Services\BusinessTripAllowanceCalculator;
use App\Services\BusinessTripAllowanceTaxCalculator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditBusinessTripRequest extends EditRecord
{
    use AttachesBusinessTripDocuments;

    protected static string $resource = BusinessTripRequestResource::class;

    /**
     * @return array<Action>
     */
    protected function buildHeaderActions(): array
    {
        /** @var BusinessTripRequest $record */
        $record = $this->record;

        return [
            Action::make('confirm')
                ->label('Confirm')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $record->canConfirm())
                ->action(function (): void {
                    BusinessTripRequestResource::confirmRequest($this->record->fresh());
                    $this->refreshFormData([
                        'status',
                        'confirmed_by',
                        'confirmed_at',
                        'salary_snapshot',
                        'month_free_cap_snapshot',
                        'non_taxable_amount',
                        'taxable_amount',
                        'gpm_amount',
                        'payable_amount',
                        'is_low_salary_case',
                    ]);
                    $this->record->refresh();
                }),

            Action::make('sepa')
                ->label('Generate SEPA payment')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Generate SEPA payment?')
                ->modalDescription('Downloads a pain.001 XML for the net payable (gross minus GPM) and marks this trip as paid.')
                ->visible(fn (): bool => $this->record->canGenerateSepa())
                ->action(function (): ?StreamedResponse {
                    return BusinessTripRequestResource::makeSepaPayments(
                        Collection::make([$this->record->fresh()]),
                    );
                }),

            Action::make('cancelRequest')
                ->label('Cancel request')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->status, [
                    BusinessTripRequestStatus::New,
                    BusinessTripRequestStatus::Confirmed,
                ], true) && $this->record->payment_status === BusinessTripPaymentStatus::Unpaid)
                ->action(function (): void {
                    $this->record->update([
                        'status' => BusinessTripRequestStatus::Canceled,
                    ]);

                    Notification::make()
                        ->title('Business trip canceled')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),

            ...parent::buildHeaderActions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var BusinessTripRequest $record */
        $record = $this->record;
        $data = $this->extractDocumentUploads($data);

        if ($record->isLocked()) {
            $expensesUpdated = array_key_exists('expenses_amount', $data);
            $expensesAmount = $expensesUpdated
                ? round(max(0, (float) ($data['expenses_amount'] ?? 0)), 2)
                : null;

            if ($expensesUpdated) {
                $record->forceFill([
                    'expenses_amount' => $expensesAmount,
                ])->save();
                $record->unsetRelation('bankStatementTransactions');
                $record->syncPaymentStatusFromBankLinks();
            }

            if ($this->pendingDocumentUploadPaths !== []) {
                $this->storeCapturedTripDocuments($record);
            }

            if ($expensesUpdated || $this->pendingDocumentUploadPaths !== []) {
                $this->record->refresh();
                $this->fillForm();

                Notification::make()
                    ->title($expensesUpdated ? 'Expenses updated' : 'Documents uploaded')
                    ->body($record->isLocked() && ! $expensesUpdated
                        ? 'Confirmed, canceled, or paid requests cannot change other trip details.'
                        : 'Allowance fields stay locked; expenses and documents can still be updated.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('This business trip cannot be edited')
                    ->body('Confirmed, canceled, or paid requests are locked. You can still update expenses and upload documents.')
                    ->warning()
                    ->send();
            }

            throw new Halt;
        }

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

        $employeeId = (int) ($data['employee_id'] ?? $record->employee_id ?? 0);
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
            (int) $record->getKey(),
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

    protected function afterSave(): void
    {
        /** @var BusinessTripRequest $record */
        $record = $this->record;
        $this->storeCapturedTripDocuments($record);
        $record->unsetRelation('bankStatementTransactions');
        $record->syncPaymentStatusFromBankLinks();
        $this->record->refresh();
    }
}
