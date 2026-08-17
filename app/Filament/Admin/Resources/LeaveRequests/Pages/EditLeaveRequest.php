<?php

namespace App\Filament\Admin\Resources\LeaveRequests\Pages;

use App\Enums\LeaveRequestStatus;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveRequestConfirmer;
use App\Services\LeaveRequestDocumentGenerator;
use App\Services\LithuanianLeavePaymentCalculator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Throwable;

class EditLeaveRequest extends EditRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected ?int $previousConfirmerId = null;

    protected ?LeaveRequestStatus $previousStatus = null;

    /**
     * @var list<int>
     */
    protected array $previousExtraApproverIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->previousConfirmerId = isset($data['confirmed_by']) ? (int) $data['confirmed_by'] : null;
        $this->previousStatus = $this->record?->status;
        $this->previousExtraApproverIds = $this->record
            ?->extraApprovers()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all() ?? [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var LeaveRequest $record */
        $record = $this->record;

        if ($record->isLocked()) {
            Notification::make()
                ->title('This leave request cannot be edited')
                ->body('Confirmed, cancellation-pending, and canceled requests are locked.')
                ->warning()
                ->send();

            throw new Halt;
        }

        $this->previousConfirmerId = $record->confirmed_by
            ? (int) $record->confirmed_by
            : null;
        $this->previousStatus = $record->status;
        $this->previousExtraApproverIds = $record->extraApprovers()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return LeaveRequestConfirmer::prepareFormData($data);
    }

    protected function afterSave(): void
    {
        /** @var LeaveRequest $leave */
        $leave = $this->record->fresh(['employee', 'leaveRequestType', 'confirmedBy', 'document', 'extraApprovers']);

        if ($this->previousStatus === LeaveRequestStatus::New) {
            try {
                LeaveRequestDocumentGenerator::syncFor($leave, auth()->user());

                Notification::make()
                    ->title('Prašymas PDF regenerated')
                    ->success()
                    ->send();
            } catch (Throwable $exception) {
                report($exception);

                Notification::make()
                    ->title('Saved, but PDF regeneration failed')
                    ->body($exception->getMessage())
                    ->warning()
                    ->send();
            }
        }

        LeaveRequestConfirmer::afterPersist(
            leave: $leave->fresh(['extraApprovers']),
            wasRecentlyCreated: false,
            previousConfirmerId: $this->previousConfirmerId,
            previousStatus: $this->previousStatus,
            previousExtraApproverIds: $this->previousExtraApproverIds,
        );

        $fresh = $leave->fresh(['extraApprovers']);

        if ($fresh?->isConfirmed() && $this->previousStatus !== LeaveRequestStatus::Confirmed) {
            Notification::make()
                ->title('Leave request confirmed')
                ->success()
                ->send();
        }
    }

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        /** @var LeaveRequest $record */
        $record = $this->record;

        if ($record->isLocked()) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
    }

    protected function buildHeaderActions(): array
    {
        /** @var LeaveRequest $record */
        $record = $this->record;

        $actions = [
            Action::make('calculateLeavePayment')
                ->label('Calculate')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->action(function (): void {
                    /** @var LeaveRequest $record */
                    $record = $this->record->loadMissing(['employee', 'leaveRequestType']);

                    $employee = $record->employee;
                    if ($employee === null) {
                        Notification::make()
                            ->title('Select an employee first')
                            ->warning()
                            ->send();

                        return;
                    }

                    if (blank($record->date_from) || blank($record->date_to)) {
                        Notification::make()
                            ->title('Set leave dates first')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = LithuanianLeavePaymentCalculator::calculate(
                        $employee,
                        $record->date_from,
                        $record->date_to,
                        $record->leaveRequestType,
                    );

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Could not calculate payment')
                            ->body($result['message'])
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->forceFill([
                        'payment_gross' => $result['gross'],
                    ])->save();

                    $this->refreshFormData(['payment_gross']);

                    Notification::make()
                        ->title('Payment (gross) calculated')
                        ->body($result['message'])
                        ->success()
                        ->send();
                }),

            Action::make('confirmLeaveRequest')
                ->label('Confirm')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm leave request')
                ->modalDescription('Mark this leave request as confirmed?')
                ->visible(function (): bool {
                    /** @var LeaveRequest $record */
                    $record = $this->record;
                    /** @var User|null $user */
                    $user = auth()->user();

                    return $record->isAwaitingConfirmationFrom($user)
                        || (
                            $record->status === LeaveRequestStatus::New
                            && ! $record->confirmerHasConfirmed()
                            && ($user?->hasRole('admin') ?? false)
                        );
                })
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var LeaveRequest $record */
                    $record = $this->record;

                    if (! LeaveRequestConfirmer::confirm($record, $user)) {
                        Notification::make()
                            ->title('Could not confirm leave request')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh(['extraApprovers']);

                    Notification::make()
                        ->title($fresh?->isConfirmed()
                            ? 'Leave request confirmed'
                            : 'Confirmation recorded')
                        ->body($fresh?->isConfirmed()
                            ? null
                            : 'Waiting for extra approvers.')
                        ->success()
                        ->send();

                    $this->redirect(LeaveRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('approveAsExtra')
                ->label('Approve as extra approver')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Extra approval')
                ->modalDescription('Record your extra approval for this leave request?')
                ->visible(fn (): bool => $this->record->isAwaitingExtraApprovalFrom(auth()->user()))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var LeaveRequest $record */
                    $record = $this->record;

                    if (! LeaveRequestConfirmer::approveAsExtra($record, $user)) {
                        Notification::make()
                            ->title('Could not record extra approval')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh();

                    Notification::make()
                        ->title($fresh?->isConfirmed()
                            ? 'Leave request confirmed'
                            : 'Extra approval recorded')
                        ->success()
                        ->send();

                    $this->redirect(LeaveRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('requestCancellation')
                ->label('Request cancellation')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Request cancellation')
                ->modalDescription('Cancellation must be approved by the person who confirmed this leave request.')
                ->visible(fn (): bool => $this->record->canRequestCancellation(auth()->user()))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var LeaveRequest $record */
                    $record = $this->record;

                    if (! LeaveRequestConfirmer::requestCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not request cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh();

                    Notification::make()
                        ->title($fresh?->status === LeaveRequestStatus::Canceled
                            ? 'Leave request canceled'
                            : 'Cancellation requested')
                        ->body($fresh?->status === LeaveRequestStatus::Canceled
                            ? null
                            : 'A notification was sent to the confirmer for approval.')
                        ->success()
                        ->send();

                    $this->redirect(LeaveRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('approveCancellation')
                ->label('Approve cancellation')
                ->icon('heroicon-o-check')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Approve cancellation')
                ->modalDescription('Cancel this confirmed leave request?')
                ->visible(fn (): bool => $this->record->isAwaitingCancellationApprovalFrom(auth()->user())
                    || (
                        $this->record->status === LeaveRequestStatus::CancellationPending
                        && (auth()->user()?->hasRole('admin') ?? false)
                    ))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var LeaveRequest $record */
                    $record = $this->record;

                    if (! LeaveRequestConfirmer::approveCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not approve cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Leave request canceled')
                        ->success()
                        ->send();

                    $this->redirect(LeaveRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('rejectCancellation')
                ->label('Keep confirmed')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reject cancellation')
                ->modalDescription('Keep this leave request as confirmed?')
                ->visible(fn (): bool => $this->record->status === LeaveRequestStatus::CancellationPending
                    && (
                        $this->record->isAwaitingCancellationApprovalFrom(auth()->user())
                        || (auth()->user()?->hasRole('admin') ?? false)
                    ))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var LeaveRequest $record */
                    $record = $this->record;

                    if (! LeaveRequestConfirmer::rejectCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not reject cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Leave request remains confirmed')
                        ->success()
                        ->send();

                    $this->redirect(LeaveRequestResource::getUrl('edit', ['record' => $record]));
                }),
        ];

        if ($record->isEditable()) {
            $actions = [...$actions, ...parent::buildHeaderActions()];
        }

        return $actions;
    }

    protected function getHeaderActions(): array
    {
        /** @var LeaveRequest $record */
        $record = $this->record;

        if ($record->isLocked()) {
            return $this->buildHeaderActions();
        }

        return parent::getHeaderActions();
    }
}
