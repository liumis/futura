<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Concerns;

use App\Models\EmployeeContract;
use App\Models\User;
use App\Services\EmployeeContractSigner;
use App\Services\EmployeeContractTerminator;
use Filament\Notifications\Notification;
use Throwable;

trait AttachesSignedContractDocument
{
    protected ?string $pendingSignedDocumentPath = null;

    /**
     * @var list<string>
     */
    protected array $pendingTerminationFilePaths = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractSignedDocumentUpload(array $data): array
    {
        $this->pendingSignedDocumentPath = EmployeeContractSigner::normalizeUploadPath($data['signed_document'] ?? null);
        $this->pendingTerminationFilePaths = EmployeeContractSigner::normalizeUploadPaths($data['additional_termination_files'] ?? null);
        unset($data['signed_document'], $data['additional_termination_files']);

        return $data;
    }

    protected function storeCapturedSignedDocument(EmployeeContract $record): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        if ($this->pendingSignedDocumentPath !== null) {
            try {
                EmployeeContractSigner::attachSignedUpload($record, $this->pendingSignedDocumentPath, $user);
            } catch (Throwable $exception) {
                Notification::make()
                    ->title('Signed document was not stored')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            }
        }

        if ($this->pendingTerminationFilePaths !== []) {
            try {
                EmployeeContractTerminator::attachAdditionalFiles(
                    $record->fresh(['terminationDocument']),
                    $this->pendingTerminationFilePaths,
                    $user,
                );
            } catch (Throwable $exception) {
                Notification::make()
                    ->title('Termination files were not stored')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            }
        }

        $this->pendingTerminationFilePaths = [];
    }
}
