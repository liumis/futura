<?php

namespace App\Filament\Admin\Resources\LeaveRequests\Pages;

use App\Enums\LeaveRequestStatus;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequestType;
use App\Services\LeaveRequestConfirmer;
use App\Services\LeaveRequestDocumentGenerator;
use App\Services\LithuanianLeavePaymentCalculator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [
            'status' => LeaveRequestStatus::New->value,
            'confirmed_by' => auth()->id(),
        ];
        $employeeId = (int) request()->query('employee_id');

        if ($employeeId > 0 && Employee::query()->whereKey($employeeId)->exists()) {
            $data['employee_id'] = $employeeId;
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
        return LeaveRequestConfirmer::prepareFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calculateLeavePayment')
                ->label('Calculate')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->action(function (): void {
                    $state = $this->form->getRawState();

                    $employeeId = (int) ($state['employee_id'] ?? 0);
                    $employee = $employeeId > 0
                        ? Employee::query()->find($employeeId)
                        : null;

                    if ($employee === null) {
                        Notification::make()
                            ->title('Select an employee first')
                            ->warning()
                            ->send();

                        return;
                    }

                    $dateFrom = $state['date_from'] ?? null;
                    $dateTo = $state['date_to'] ?? null;

                    if (blank($dateFrom) || blank($dateTo)) {
                        Notification::make()
                            ->title('Set leave dates first')
                            ->warning()
                            ->send();

                        return;
                    }

                    $typeId = (int) ($state['leave_request_type_id'] ?? 0);
                    $type = $typeId > 0
                        ? LeaveRequestType::query()->find($typeId)
                        : null;

                    $result = LithuanianLeavePaymentCalculator::calculate(
                        $employee,
                        (string) $dateFrom,
                        (string) $dateTo,
                        $type,
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Could not calculate payment')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->form->fill([
                        ...$state,
                        'payment_gross' => number_format($result['gross'], 2, '.', ''),
                    ]);

                    Notification::make()
                        ->title('Payment (gross) calculated')
                        ->body($result['message'])
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function afterCreate(): void
    {
        try {
            LeaveRequestDocumentGenerator::syncFor(
                $this->record->fresh(['employee', 'leaveRequestType', 'document']),
                auth()->user(),
            );

            Notification::make()
                ->title('Prašymas document created')
                ->body('A Lithuanian Prašymas PDF was generated and stored in Documents.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Leave request saved, but document failed')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }

        LeaveRequestConfirmer::afterPersist(
            leave: $this->record->fresh(['extraApprovers']),
            wasRecentlyCreated: true,
        );

        $leave = $this->record->fresh(['extraApprovers']);

        if ($leave?->isConfirmed()) {
            Notification::make()
                ->title('Leave request confirmed')
                ->body('All required confirmations and extra approvals are complete.')
                ->success()
                ->send();

            return;
        }

        if ($leave?->confirmerHasConfirmed() && ! $leave->extraApprovalsComplete()) {
            Notification::make()
                ->title('Waiting for extra approvers')
                ->body('Your confirmation was recorded. Extra approvers were notified.')
                ->success()
                ->send();

            return;
        }

        if (filled($leave?->confirmed_by)) {
            Notification::make()
                ->title('Confirmation requested')
                ->body('Notifications were sent to the confirmer and extra approvers.')
                ->success()
                ->send();
        }
    }
}
