<?php

namespace App\Services;

use App\Models\ProjectExpense;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SharepointProjectExpenseUploader
{
    /**
     * Folder path: {root}/project-expenses/{id}-{label}
     */
    public static function folderPathForExpense(ProjectExpense $expense, ?SharepointSetting $settings = null): string
    {
        $settings ??= SharepointSetting::instance();
        $client = SharepointGraphClient::make($settings);

        $segments = [];

        if (filled($settings->root_folder_path)) {
            $root = $client->normalizePath((string) $settings->root_folder_path);
            if ($root !== '') {
                foreach (explode('/', $root) as $segment) {
                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                }
            }
        }

        $segments[] = 'project-expenses';
        $segments[] = self::expenseFolderName($expense, $client);

        return implode('/', $segments);
    }

    public static function expenseFolderName(ProjectExpense $expense, ?SharepointGraphClient $client = null): string
    {
        $client ??= SharepointGraphClient::make();
        $safe = $client->sanitizeFolderName($expense->folderLabel());

        return $expense->getKey().'-'.$safe;
    }

    /**
     * @param  list<string>  $localPaths
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string}>
     */
    public static function ingestLocalUploads(ProjectExpense $expense, array $localPaths): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Project expense photos are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        if (! $expense->exists) {
            throw new RuntimeException('Save the project expense before uploading files to SharePoint.');
        }

        $folderPath = self::folderPathForExpense($expense, $settings);
        $client = SharepointGraphClient::make($settings);

        $existing = [];
        if (is_array($expense->sharepoint_files)) {
            $existing = array_values(array_filter(
                $expense->sharepoint_files,
                fn ($row): bool => is_array($row) && filled($row['path'] ?? null),
            ));
        }

        $meta = $existing;

        foreach ($localPaths as $localPath) {
            if (! Storage::disk('public')->exists((string) $localPath)) {
                throw new RuntimeException('Uploaded file is missing: '.$localPath);
            }

            $binary = Storage::disk('public')->get((string) $localPath);
            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not read the uploaded file.');
            }

            $remoteName = $client->sanitizeFileName(basename(str_replace('\\', '/', (string) $localPath)));
            $remoteName = self::uniqueRemoteName($remoteName, $meta);

            $item = $client->uploadBinary($folderPath, $remoteName, $binary);
            $path = $folderPath.'/'.$remoteName;

            $meta[] = [
                'name' => $remoteName,
                'path' => $path,
                'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
                'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
                'uploaded_at' => SharepointTaskUploader::resolveUploadedAt($item) ?? now()->toIso8601String(),
            ];
        }

        $first = $meta[0];

        $expense->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? $expense->sharepoint_web_url,
            'sharepoint_item_id' => $first['item_id'] ?? $expense->sharepoint_item_id,
            'sharepoint_path' => $first['path'] ?? $expense->sharepoint_path,
            'sharepoint_files' => $meta,
            'attachments' => null,
        ])->save();

        DocumentBinaryStore::deleteLocalPaths($localPaths);

        Log::info('Project expense files stored on SharePoint', [
            'project_expense_id' => $expense->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        ActivityLogger::logFileUploaded('Project expense photos', 'multi', $expense, [
            'count' => count($meta),
            'sharepoint_folder' => $folderPath,
            'file_names' => array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
                $meta,
            ))),
        ]);

        return $meta;
    }

    /**
     * @param  list<array{name?: string, path?: string, item_id?: ?string, web_url?: ?string}>  $existing
     */
    protected static function uniqueRemoteName(string $remoteName, array $existing): string
    {
        $used = [];
        foreach ($existing as $row) {
            if (is_array($row) && filled($row['name'] ?? null)) {
                $used[strtolower((string) $row['name'])] = true;
            }
        }

        if (! isset($used[strtolower($remoteName)])) {
            return $remoteName;
        }

        $base = pathinfo($remoteName, PATHINFO_FILENAME);
        $ext = pathinfo($remoteName, PATHINFO_EXTENSION);
        $n = 2;

        do {
            $candidate = $base.'-'.$n.($ext !== '' ? '.'.$ext : '');
            $n++;
        } while (isset($used[strtolower($candidate)]));

        return $candidate;
    }
}
