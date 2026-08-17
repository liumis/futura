<?php

namespace App\Filament\Admin\Resources\Documents;

use App\Filament\Admin\Support\DokobitSigningUi;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentDokobitSigner;
use App\Services\DocumentWorkflow;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Livewire\Component;

class DocumentSignAction
{
    public static function canUse(Document $record): bool
    {
        if (! auth()->user() instanceof User) {
            return false;
        }

        if ($record->trashed()) {
            return false;
        }

        if ($record->canManageApprovalOrSigningWorkflow()) {
            return true;
        }

        if (! $record->awaitsDokobitSignature()) {
            return false;
        }

        $user = auth()->user();
        $signing = $record->documentSigning;
        if ($signing === null || ! $signing->isPending()) {
            return $record->contractSigning?->isPending() === true;
        }

        $signer = $signing->signerForUser($user);

        return $signer !== null && ! $signer->is_external && ! $signer->hasSigned();
    }

    public static function make(string $name = 'approvals_sign'): Action
    {
        return Action::make($name)
            ->label(fn (Document $record): string => $record->canManageApprovalOrSigningWorkflow()
                ? 'Approvals & Sign'
                : 'Open Dokobit')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->modalHeading('Approvals & Dokobit signing')
            ->modalDescription('Choose internal approvers and/or Dokobit signers. You can add more people even if some approvals already exist. External invitees sign on Dokobit via email.')
            ->modalSubmitActionLabel('Apply')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modal(fn (Document $record): bool => $record->canManageApprovalOrSigningWorkflow())
            ->visible(fn (Document $record): bool => ! $record->trashed() && auth()->user() instanceof User)
            ->disabled(fn (Document $record): bool => ! self::canUse($record))
            ->fillForm(function (Document $record): array {
                if (! $record->canManageApprovalOrSigningWorkflow()) {
                    return [];
                }

                $select = DocumentDokobitSigner::signerSelectOptions(auth()->user() instanceof User ? auth()->user() : null);

                return [
                    DocumentApprovalForm::APPROVER_IDS => [],
                    DocumentApprovalForm::SIGNER_IDS => $select['defaults'],
                    DocumentApprovalForm::EXTERNAL_INVITEES => [],
                ];
            })
            ->form(function (Document $record): array {
                if (! $record->canManageApprovalOrSigningWorkflow()) {
                    return [];
                }

                return DocumentApprovalForm::modalFormComponents();
            })
            ->action(function (Document $record, array $data, Component $livewire) {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return null;
                }

                $wantsAssign = $record->canManageApprovalOrSigningWorkflow()
                    && (
                        filled($data[DocumentApprovalForm::APPROVER_IDS] ?? null)
                        || filled($data[DocumentApprovalForm::SIGNER_IDS] ?? null)
                        || filled($data[DocumentApprovalForm::EXTERNAL_INVITEES] ?? null)
                    );

                if (! $wantsAssign && $record->awaitsDokobitSignature()) {
                    DokobitSigningUi::openForDocument($livewire, $record->fresh([
                        'documentSigning.signers',
                        'contractSigning.signers',
                        'approvers',
                    ]), $user);

                    return null;
                }

                try {
                    $result = DocumentWorkflow::start(
                        $record,
                        $user,
                        $data[DocumentApprovalForm::APPROVER_IDS] ?? [],
                        $data[DocumentApprovalForm::SIGNER_IDS] ?? [],
                        $data[DocumentApprovalForm::EXTERNAL_INVITEES] ?? [],
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not update workflow')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                $document = $result['document'];
                $signing = $result['signing'];

                $parts = [];
                if ($document->hasDocumentApprovers()) {
                    $parts[] = 'Approvers updated';
                }
                if ($signing !== null) {
                    $externalCount = $signing->signers->where('is_external', true)->count();
                    $parts[] = 'Dokobit signing started'
                        .($externalCount > 0 ? " ({$externalCount} external invite email(s) sent)" : '');
                }

                Notification::make()
                    ->title('Workflow updated')
                    ->body(implode('. ', $parts).'.')
                    ->success()
                    ->send();

                if (
                    $signing !== null
                    && $document->awaitsDokobitSignature()
                    && $signing->signerForUser($user)
                ) {
                    DokobitSigningUi::openForDocument($livewire, $document, $user);
                }

                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }

                return null;
            });
    }
}
