<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Document;
use App\Models\DokobitSetting;
use App\Services\DocumentDokobitSigner;
use App\Services\EmployeeContractSigner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

trait HasDokobitSigningModal
{
    public function dokobitFrameAction(): Action
    {
        return Action::make('dokobitFrame')
            ->modalHeading('Sign with Dokobit')
            ->modalDescription('Complete signing below. When finished, the signed PDF is saved to Documents automatically.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->closeModalByClickingAway(false)
            ->modalContent(function (Action $action): View {
                $arguments = $action->getArguments();

                return view('filament.admin.components.dokobit-signing-frame', [
                    'url' => (string) ($arguments['url'] ?? ''),
                    'documentId' => (int) ($arguments['documentId'] ?? 0),
                    'scriptBase' => rtrim(DokobitSetting::instance()->activeApiUrl(), '/'),
                ]);
            });
    }

    public function openDokobitSigningFrame(string $url, int $documentId): void
    {
        $arguments = [
            'url' => $url,
            'documentId' => $documentId,
        ];

        if (! empty($this->mountedActions ?? [])) {
            $this->replaceMountedAction('dokobitFrame', $arguments);

            return;
        }

        $this->mountAction('dokobitFrame', $arguments);
    }

    public function syncDokobitDocument(int $documentId): void
    {
        $document = Document::query()
            ->with(['documentSigning.signers', 'contractSigning.signers'])
            ->find($documentId);

        if ($document === null) {
            Notification::make()
                ->title('Document not found')
                ->danger()
                ->send();

            $this->unmountAction();

            return;
        }

        $synced = false;
        $error = null;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                if ($document->documentSigning?->isPending()) {
                    DocumentDokobitSigner::syncStatus($document->documentSigning->fresh(['signers']));
                    $synced = true;
                }

                if ($document->contractSigning?->isPending()) {
                    EmployeeContractSigner::syncStatus($document->contractSigning->fresh(['signers', 'contract']));
                    $synced = true;
                }

                $document->refresh();

                if ($document->isApproved()) {
                    break;
                }

                if ($attempt < 3) {
                    usleep(500_000);
                    $document->load(['documentSigning.signers', 'contractSigning.signers']);
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                if ($attempt < 3) {
                    usleep(500_000);
                }
            }
        }

        $this->unmountAction();

        $document->refresh();

        if ($document->isApproved()) {
            Notification::make()
                ->title('Document signed')
                ->body('Signed PDF downloaded and saved in Documents.')
                ->success()
                ->send();
        } elseif ($synced) {
            Notification::make()
                ->title('Signature recorded')
                ->body('Waiting for remaining signers. Click Sign again to continue or refresh status.')
                ->success()
                ->send();
        } elseif ($error !== null) {
            Notification::make()
                ->title('Could not sync signed document')
                ->body($error)
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Signing session updated')
                ->success()
                ->send();
        }

        if (method_exists($this, 'resetTable')) {
            $this->resetTable();
        }

        if (method_exists($this, 'fillForm') && isset($this->record)) {
            $this->record?->refresh();
            $this->fillForm();
        }
    }

    public function reportDokobitError(string $message): void
    {
        Notification::make()
            ->title('Dokobit signing failed')
            ->body($message !== '' ? $message : 'Unable to sign document.')
            ->danger()
            ->send();
    }
}
