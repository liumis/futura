<?php

namespace App\Filament\Admin\Resources\Documents\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Concerns\UploadsDocumentToSharepoint;
use App\Filament\Admin\Resources\Documents\DocumentApprovalForm;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

class CreateDocument extends CreateRecord
{
    use HasDokobitSigningModal;
    use UploadsDocumentToSharepoint;

    protected static string $resource = DocumentResource::class;

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

    protected bool $shouldApproveAfterCreate = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['file_path'] ?? null);
        unset($data['file_path']);

        $this->pendingWorkflow = DocumentApprovalForm::extractFromFormData($data);

        try {
            DocumentApprovalForm::assertCanStartWithFiles(
                $this->pendingWorkflow,
                $this->pendingLocalUploads,
                null,
                $this->shouldApproveAfterCreate,
            );
        } catch (RuntimeException $exception) {
            $this->shouldApproveAfterCreate = false;

            Notification::make()
                ->title('Cannot save')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        $data['user_uploaded_id'] = auth()->id();
        $data['flag_approved'] = false;

        return $data;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Save');
    }

    protected function getCreateAndApproveFormAction(): Action
    {
        return Action::make('createAndApprove')
            ->label('Save and approve')
            ->color('success')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalHeading('Save and approve')
            ->modalDescription('Save this document and approve it now? The document will be locked after approval. Do not select other approvers/signers for this action.')
            ->modalSubmitActionLabel('Save and approve')
            ->action('createAndApprove');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Save & create another');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAndApproveFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    public function createAndApprove(): void
    {
        $this->shouldApproveAfterCreate = true;

        $this->create();
    }

    protected function afterCreate(): void
    {
        if ($this->record) {
            $this->uploadDocumentFilesToSharepoint($this->record, $this->pendingLocalUploads);
        }

        $this->pendingLocalUploads = [];

        if ($this->record && DocumentApprovalForm::hasSelection($this->pendingWorkflow)) {
            $result = DocumentApprovalForm::startAfterSave(
                $this->record,
                $this->pendingWorkflow,
                $this,
            );

            if ($result !== null) {
                $this->record = $result['document'];
            }

            $this->shouldApproveAfterCreate = false;
            $this->pendingWorkflow = [
                DocumentApprovalForm::APPROVER_IDS => [],
                DocumentApprovalForm::SIGNER_IDS => [],
                DocumentApprovalForm::EXTERNAL_INVITEES => [],
            ];

            return;
        }

        if ($this->shouldApproveAfterCreate && $this->record) {
            $this->shouldApproveAfterCreate = false;

            if (DocumentResource::approveDocument($this->record->fresh(), notify: false)) {
                $this->record->refresh();
            }
        }

        $this->pendingWorkflow = [
            DocumentApprovalForm::APPROVER_IDS => [],
            DocumentApprovalForm::SIGNER_IDS => [],
            DocumentApprovalForm::EXTERNAL_INVITEES => [],
        ];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        if ($this->record?->isApproved()) {
            return 'Document saved and approved';
        }

        return parent::getCreatedNotificationTitle();
    }
}
