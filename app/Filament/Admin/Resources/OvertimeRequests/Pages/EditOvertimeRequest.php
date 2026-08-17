<?php

namespace App\Filament\Admin\Resources\OvertimeRequests\Pages;

use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\OvertimeRequestConfirmer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

class EditOvertimeRequest extends EditRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    protected ?int $previousConfirmerId = null;

    protected ?OvertimeRequestStatus $previousStatus = null;

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
        /** @var OvertimeRequest $record */
        $record = $this->record;

        if ($record->isLocked()) {
            Notification::make()
                ->title('This overtime request cannot be edited')
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

        return OvertimeRequestConfirmer::prepareFormData($data);
    }

    protected function afterSave(): void
    {
        /** @var OvertimeRequest $overtime */
        $overtime = $this->record->fresh(['employee', 'overtimeRequestType', 'confirmedBy', 'extraApprovers']);

        OvertimeRequestConfirmer::afterPersist(
            overtime: $overtime->fresh(['extraApprovers']),
            wasRecentlyCreated: false,
            previousConfirmerId: $this->previousConfirmerId,
            previousStatus: $this->previousStatus,
            previousExtraApproverIds: $this->previousExtraApproverIds,
        );

        $fresh = $overtime->fresh(['extraApprovers']);

        if ($fresh?->isConfirmed() && $this->previousStatus !== OvertimeRequestStatus::Confirmed) {
            Notification::make()
                ->title('Overtime request confirmed')
                ->success()
                ->send();
        }
    }

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        /** @var OvertimeRequest $record */
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
        /** @var OvertimeRequest $record */
        $record = $this->record;

        $actions = [
            Action::make('confirmOvertimeRequest')
                ->label('Confirm')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm overtime request')
                ->modalDescription('Mark this overtime request as confirmed?')
                ->visible(function (): bool {
                    /** @var OvertimeRequest $record */
                    $record = $this->record;
                    /** @var User|null $user */
                    $user = auth()->user();

                    return $record->isAwaitingConfirmationFrom($user)
                        || (
                            $record->status === OvertimeRequestStatus::New
                            && ! $record->confirmerHasConfirmed()
                            && ($user?->hasRole('admin') ?? false)
                        );
                })
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var OvertimeRequest $record */
                    $record = $this->record;

                    if (! OvertimeRequestConfirmer::confirm($record, $user)) {
                        Notification::make()
                            ->title('Could not confirm overtime request')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh(['extraApprovers']);

                    Notification::make()
                        ->title($fresh?->isConfirmed()
                            ? 'Overtime request confirmed'
                            : 'Confirmation recorded')
                        ->body($fresh?->isConfirmed()
                            ? null
                            : 'Waiting for extra approvers.')
                        ->success()
                        ->send();

                    $this->redirect(OvertimeRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('approveAsExtra')
                ->label('Approve as extra approver')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Extra approval')
                ->modalDescription('Record your extra approval for this overtime request?')
                ->visible(fn (): bool => $this->record->isAwaitingExtraApprovalFrom(auth()->user()))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var OvertimeRequest $record */
                    $record = $this->record;

                    if (! OvertimeRequestConfirmer::approveAsExtra($record, $user)) {
                        Notification::make()
                            ->title('Could not record extra approval')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh();

                    Notification::make()
                        ->title($fresh?->isConfirmed()
                            ? 'Overtime request confirmed'
                            : 'Extra approval recorded')
                        ->success()
                        ->send();

                    $this->redirect(OvertimeRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('requestCancellation')
                ->label('Request cancellation')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Request cancellation')
                ->modalDescription('Cancellation must be approved by the person who confirmed this overtime request.')
                ->visible(fn (): bool => $this->record->canRequestCancellation(auth()->user()))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var OvertimeRequest $record */
                    $record = $this->record;

                    if (! OvertimeRequestConfirmer::requestCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not request cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fresh = $record->fresh();

                    Notification::make()
                        ->title($fresh?->status === OvertimeRequestStatus::Canceled
                            ? 'Overtime request canceled'
                            : 'Cancellation requested')
                        ->body($fresh?->status === OvertimeRequestStatus::Canceled
                            ? null
                            : 'A notification was sent to the confirmer for approval.')
                        ->success()
                        ->send();

                    $this->redirect(OvertimeRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('approveCancellation')
                ->label('Approve cancellation')
                ->icon('heroicon-o-check')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Approve cancellation')
                ->modalDescription('Cancel this confirmed overtime request?')
                ->visible(fn (): bool => $this->record->isAwaitingCancellationApprovalFrom(auth()->user())
                    || (
                        $this->record->status === OvertimeRequestStatus::CancellationPending
                        && (auth()->user()?->hasRole('admin') ?? false)
                    ))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var OvertimeRequest $record */
                    $record = $this->record;

                    if (! OvertimeRequestConfirmer::approveCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not approve cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Overtime request canceled')
                        ->success()
                        ->send();

                    $this->redirect(OvertimeRequestResource::getUrl('edit', ['record' => $record]));
                }),

            Action::make('rejectCancellation')
                ->label('Keep confirmed')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reject cancellation')
                ->modalDescription('Keep this overtime request as confirmed?')
                ->visible(fn (): bool => $this->record->status === OvertimeRequestStatus::CancellationPending
                    && (
                        $this->record->isAwaitingCancellationApprovalFrom(auth()->user())
                        || (auth()->user()?->hasRole('admin') ?? false)
                    ))
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    /** @var OvertimeRequest $record */
                    $record = $this->record;

                    if (! OvertimeRequestConfirmer::rejectCancellation($record, $user)) {
                        Notification::make()
                            ->title('Could not reject cancellation')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Overtime request remains confirmed')
                        ->success()
                        ->send();

                    $this->redirect(OvertimeRequestResource::getUrl('edit', ['record' => $record]));
                }),
        ];

        if ($record->isEditable()) {
            $actions = [...$actions, ...parent::buildHeaderActions()];
        }

        return $actions;
    }

    protected function getHeaderActions(): array
    {
        /** @var OvertimeRequest $record */
        $record = $this->record;

        if ($record->isLocked()) {
            return $this->buildHeaderActions();
        }

        return parent::getHeaderActions();
    }
}
