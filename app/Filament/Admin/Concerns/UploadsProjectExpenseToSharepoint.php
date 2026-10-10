<?php

namespace App\Filament\Admin\Concerns;

use App\Models\ProjectExpense;
use App\Models\SharepointSetting;
use App\Services\DocumentBinaryStore;
use App\Services\SharepointProjectExpenseUploader;
use Filament\Notifications\Notification;
use Throwable;

trait UploadsProjectExpenseToSharepoint
{
    /**
     * @param  list<string>  $localPaths
     */
    protected function uploadProjectExpenseFilesToSharepoint(?ProjectExpense $expense, array $localPaths): void
    {
        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));

        if ($expense === null || $localPaths === []) {
            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::deleteLocalPaths($localPaths);
            $expense->forceFill(['attachments' => null])->save();

            Notification::make()
                ->title('SharePoint required')
                ->body('Project expense photos are stored only on SharePoint. Enable and configure System → SharePoint, then upload again.')
                ->danger()
                ->send();

            return;
        }

        try {
            $files = SharepointProjectExpenseUploader::ingestLocalUploads($expense->fresh(['project']), $localPaths);
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
