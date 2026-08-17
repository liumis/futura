<?php

namespace App\Filament\Admin\Resources\Documents\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Concerns\UploadsDocumentToSharepoint;
use App\Filament\Admin\Resources\Documents\DocumentApprovalForm;
use App\Filament\Admin\Resources\Documents\DocumentCloneAction;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Documents\DocumentSignAction;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use RuntimeException;

class EditDocument extends EditRecord
{
    use HasDokobitSigningModal;
    use UploadsDocumentToSharepoint;

    protected static string $resource = DocumentResource::class;

    protected bool $sharepointFileWasUploaded = false;

    /**
     * @var list<string>
     */
    protected array $pendingLocalUploads = [];

    /**
     * @var array{
     *     approver_user_ids: list<int>,
     *     signer_user_ids: list<int>,
     *     external_invitees: list<array{name?: string, surname?: string, email?: string}>
     * }
     */
    protected array $pendingWorkflow = [
        DocumentApprovalForm::APPROVER_IDS => [],
        DocumentApprovalForm::SIGNER_IDS => [],
        DocumentApprovalForm::EXTERNAL_INVITEES => [],
    ];

    protected bool $shouldApproveAfterSave = false;

    protected bool $approvedDuringSave = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->isLocked()) {
            Notification::make()
                ->title('Document is locked')
                ->body('Approved documents cannot be edited.')
                ->warning()
                ->send();

            $this->halt();
        }

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['file_path'] ?? null);
        $this->sharepointFileWasUploaded = $this->pendingLocalUploads !== [];
        $this->pendingWorkflow = DocumentApprovalForm::extractFromFormData($data);

        try {
            DocumentApprovalForm::assertCanStartWithFiles(
                $this->pendingWorkflow,
                $this->pendingLocalUploads,
                $this->record,
                $this->shouldApproveAfterSave,
            );
        } catch (RuntimeException $exception) {
            $this->shouldApproveAfterSave = false;

            Notification::make()
                ->title('Cannot save')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        // Keep the original author; only fill if missing.
        if (blank($this->record->user_uploaded_id)) {
            $data['user_uploaded_id'] = auth()->id();
        }

        unset($data['file_path']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->sharepointFileWasUploaded) {
            $this->uploadDocumentFilesToSharepoint(
                $this->record,
                $this->pendingLocalUploads,
            );
        }

        $this->sharepointFileWasUploaded = false;
        $this->pendingLocalUploads = [];

        if (
            $this->record?->canStartApprovalOrSigningWorkflow()
            && DocumentApprovalForm::hasSelection($this->pendingWorkflow)
        ) {
            $result = DocumentApprovalForm::startAfterSave(
                $this->record,
                $this->pendingWorkflow,
                $this,
            );

            if ($result !== null) {
                $this->record = $result['document'];
            }

            $this->shouldApproveAfterSave = false;
            $this->pendingWorkflow = [
                DocumentApprovalForm::APPROVER_IDS => [],
                DocumentApprovalForm::SIGNER_IDS => [],
                DocumentApprovalForm::EXTERNAL_INVITEES => [],
            ];
            $this->fillForm();

            return;
        }

        if ($this->shouldApproveAfterSave) {
            $this->shouldApproveAfterSave = false;

            if (DocumentResource::approveDocument($this->record->fresh(), notify: false)) {
                $this->approvedDuringSave = true;
            }

            $this->record->refresh();
            $this->fillForm();
        }

        $this->pendingWorkflow = [
            DocumentApprovalForm::APPROVER_IDS => [],
            DocumentApprovalForm::SIGNER_IDS => [],
            DocumentApprovalForm::EXTERNAL_INVITEES => [],
        ];
    }

    public function saveAndApprove(): void
    {
        $this->shouldApproveAfterSave = true;

        $this->save();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        if ($this->approvedDuringSave) {
            $this->approvedDuringSave = false;

            return 'Document saved and approved';
        }

        return parent::getSavedNotificationTitle();
    }

    protected function getHeaderActions(): array
    {
        $actions = $this->insertSaveBeforeDelete($this->buildHeaderActions());
        $result = [];

        foreach ($actions as $action) {
            $result[] = $action;

            if ($action instanceof Action && $action->getName() === 'save') {
                $result[] = $this->makeSaveAndApproveAction();
            }
        }

        return $result;
    }

    protected function makeSaveAndApproveAction(): Action
    {
        return Action::make('saveAndApprove')
            ->label('Save and approve')
            ->color('success')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalHeading('Save and approve')
            ->modalDescription(fn (): string => 'Save "'.$this->record->name.'" and approve it now? The document will be locked after approval. Do not select other approvers/signers for this action.')
            ->modalSubmitActionLabel('Save and approve')
            ->action('saveAndApprove')
            ->visible(fn (): bool => $this->canSaveAndApprove());
    }

    protected function canSaveAndApprove(): bool
    {
        if ($this->record?->trashed()) {
            return false;
        }

        return $this->record?->currentUserCanApprove() ?? false;
    }

    protected function buildHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve document')
                ->modalDescription(fn (): string => ($this->record?->currentUserHasPendingApproval() || $this->record?->hasDocumentApprovers())
                    ? 'Confirm your approval for "'.$this->record->name.'"? Other pending approvals/signatures may still be required.'
                    : 'Approve and lock "'.$this->record->name.'"?')
                ->modalSubmitActionLabel('Approve')
                ->visible(fn (): bool => ! ($this->record?->trashed() ?? true)
                    && ($this->record?->currentUserCanApprove() ?? false))
                ->action(function (): void {
                    $record = $this->record;
                    if ($record->paymentReport !== null) {
                        DocumentResource::confirmPaymentReportDocument($record);
                    } elseif ($record->dividendPaymentReport !== null) {
                        DocumentResource::confirmDividendPaymentReportDocument($record);
                    } elseif ($record->currentUserHasPendingApproval() || $record->hasDocumentApprovers()) {
                        DocumentResource::confirmOrJoinDocumentApproval($record);
                    } else {
                        DocumentResource::approveDocument($record);
                    }
                    $this->record->refresh();
                    $this->fillForm();
                }),

            Action::make('sign')
                ->label('Sign')
                ->icon('heroicon-o-finger-print')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sign document')
                ->modalDescription(fn (): string => ($this->record?->awaitsDokobitSignature() ?? false)
                    ? 'Open Dokobit to complete your digital signature for "'.$this->record->name.'".'
                    : 'Start Dokobit digital signing for "'.$this->record->name.'"? You will be the signer.')
                ->modalSubmitActionLabel('Sign')
                ->visible(fn (): bool => ! ($this->record?->trashed() ?? true)
                    && ($this->record?->currentUserCanSign() ?? false))
                ->action(function () {
                    $result = DocumentResource::signDocument($this->record, $this);
                    $this->record->refresh();
                    $this->fillForm();

                    return $result;
                }),

            DocumentSignAction::make()
                ->record($this->getRecord())
                ->visible(fn (): bool => ! ($this->record?->trashed() ?? true)),

            DocumentCloneAction::make()
                ->record($this->getRecord()),

            DeleteAction::make()
                ->visible(fn (): bool => ! ($this->record?->trashed() ?? true)),

            RestoreAction::make()
                ->visible(fn (): bool => $this->record?->trashed() ?? false),
        ];
    }

    protected function makeHeaderSaveAction(): Action
    {
        return parent::makeHeaderSaveAction()
            ->visible(fn (): bool => ! ($this->record?->isLocked() ?? false)
                && ! ($this->record?->trashed() ?? false));
    }
}
