<?php

namespace App\Filament\Admin\Resources\BusinessTripRequests\Concerns;

use App\Models\BusinessTripRequest;
use App\Models\User;
use App\Services\BusinessTripDocumentService;
use Filament\Notifications\Notification;
use Throwable;

trait AttachesBusinessTripDocuments
{
    /**
     * @var list<string>
     */
    protected array $pendingDocumentUploadPaths = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractDocumentUploads(array $data): array
    {
        $this->pendingDocumentUploadPaths = BusinessTripDocumentService::normalizeUploadPaths(
            $data['document_uploads'] ?? null,
        );
        unset($data['document_uploads']);

        return $data;
    }

    protected function storeCapturedTripDocuments(BusinessTripRequest $record): void
    {
        if ($this->pendingDocumentUploadPaths === []) {
            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            $this->pendingDocumentUploadPaths = [];

            return;
        }

        try {
            $document = BusinessTripDocumentService::attachUploads(
                $record->fresh(['employee']),
                $this->pendingDocumentUploadPaths,
                $user,
            );

            Notification::make()
                ->title('Trip documents uploaded')
                ->body($document->name)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Trip documents were not stored')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        $this->pendingDocumentUploadPaths = [];
    }
}
