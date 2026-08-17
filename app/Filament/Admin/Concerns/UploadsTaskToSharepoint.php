<?php

namespace App\Filament\Admin\Concerns;

use App\Models\SharepointSetting;
use App\Models\Todo;
use App\Services\DocumentBinaryStore;
use App\Services\SharepointTaskUploader;
use Filament\Notifications\Notification;
use Throwable;

trait UploadsTaskToSharepoint
{
    /**
     * @param  list<string>  $localPaths
     */
    protected function uploadTaskFilesToSharepoint(?Todo $todo, array $localPaths): void
    {
        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));

        if ($todo === null || $localPaths === []) {
            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::deleteLocalPaths($localPaths);
            $todo->forceFill(['attachments' => null])->save();

            Notification::make()
                ->title('SharePoint required')
                ->body('Task files are stored only on SharePoint. Enable and configure System → SharePoint, then upload again.')
                ->danger()
                ->send();

            return;
        }

        try {
            $files = SharepointTaskUploader::ingestLocalUploads($todo->fresh(), $localPaths);
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
