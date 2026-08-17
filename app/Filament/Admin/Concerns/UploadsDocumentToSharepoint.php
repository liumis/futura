<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Document;
use App\Models\SharepointSetting;
use App\Services\DocumentBinaryStore;
use Filament\Notifications\Notification;
use Throwable;

trait UploadsDocumentToSharepoint
{
    /**
     * @param  list<string>  $localPaths
     */
    protected function uploadDocumentFilesToSharepoint(?Document $document, array $localPaths): void
    {
        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));

        if ($document === null || $localPaths === []) {
            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::deleteLocalPaths($localPaths);
            $document->forceFill(['file_path' => null])->save();

            Notification::make()
                ->title('SharePoint required')
                ->body('Documents are stored only on SharePoint. Enable and configure System → SharePoint, then upload the file again.')
                ->danger()
                ->send();

            return;
        }

        try {
            $files = DocumentBinaryStore::ingestLocalUploads(
                $document->fresh(['documentType']),
                $localPaths,
            );

            $paths = array_column($files, 'path');

            Notification::make()
                ->title(count($files) === 1 ? 'Stored on SharePoint' : 'Stored '.count($files).' files on SharePoint')
                ->body(implode("\n", $paths))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('SharePoint upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @deprecated Use uploadDocumentFilesToSharepoint()
     */
    protected function uploadDocumentFileToSharepoint(?Document $document, bool $fileWasUploaded): void
    {
        if (! $fileWasUploaded || $document === null || blank($document->file_path)) {
            return;
        }

        $this->uploadDocumentFilesToSharepoint($document, [(string) $document->file_path]);
    }

    /**
     * Normalize Filament file upload state into a list of local public-disk paths.
     *
     * @param  mixed  $state
     * @return list<string>
     */
    protected function normalizeUploadedFilePaths(mixed $state): array
    {
        if (blank($state)) {
            return [];
        }

        if (is_array($state)) {
            return array_values(array_filter($state, fn ($path): bool => filled($path) && is_string($path)));
        }

        return is_string($state) ? [$state] : [];
    }
}
