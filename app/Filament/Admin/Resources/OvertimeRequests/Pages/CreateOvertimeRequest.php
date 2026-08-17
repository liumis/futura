<?php

namespace App\Filament\Admin\Resources\OvertimeRequests\Pages;

use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\Employee;
use App\Services\OvertimeRequestConfirmer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOvertimeRequest extends CreateRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [
            'status' => OvertimeRequestStatus::New->value,
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
        return OvertimeRequestConfirmer::prepareFormData($data);
    }

    protected function afterCreate(): void
    {
        OvertimeRequestConfirmer::afterPersist(
            overtime: $this->record->fresh(['extraApprovers']),
            wasRecentlyCreated: true,
        );

        $overtime = $this->record->fresh(['extraApprovers']);

        if ($overtime?->isConfirmed()) {
            Notification::make()
                ->title('Overtime request confirmed')
                ->body('All required confirmations and extra approvals are complete.')
                ->success()
                ->send();

            return;
        }

        if ($overtime?->confirmerHasConfirmed() && ! $overtime->extraApprovalsComplete()) {
            Notification::make()
                ->title('Waiting for extra approvers')
                ->body('Your confirmation was recorded. Extra approvers were notified.')
                ->success()
                ->send();

            return;
        }

        if (filled($overtime?->confirmed_by)) {
            Notification::make()
                ->title('Confirmation requested')
                ->body('Notifications were sent to the confirmer and extra approvers.')
                ->success()
                ->send();
        }
    }
}
