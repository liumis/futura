<?php

namespace App\Services;

use App\Models\SharepointSetting;
use App\Models\Todo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SharepointTaskUploader
{
    /**
     * Folder path: {root}/tasks/{id}-{sanitizedTitle}
     */
    public static function folderPathForTask(Todo $todo, ?SharepointSetting $settings = null): string
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

        $segments[] = 'tasks';
        $segments[] = self::taskFolderName($todo, $client);

        return implode('/', $segments);
    }

    public static function taskFolderName(Todo $todo, ?SharepointGraphClient $client = null): string
    {
        $client ??= SharepointGraphClient::make();
        $title = filled($todo->title) ? (string) $todo->title : 'task';
        $safeTitle = $client->sanitizeFolderName($title);

        return $todo->getKey().'-'.$safeTitle;
    }

    /**
     * Upload local public-disk files to SharePoint and append metadata on the todo.
     *
     * @param  list<string>  $localPaths
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string}>
     */
    public static function ingestLocalUploads(Todo $todo, array $localPaths): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Task files are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        if (! $todo->exists) {
            throw new RuntimeException('Save the task before uploading files to SharePoint.');
        }

        $folderPath = self::folderPathForTask($todo, $settings);
        $client = SharepointGraphClient::make($settings);

        $existing = [];
        if (is_array($todo->sharepoint_files)) {
            $existing = array_values(array_filter(
                $todo->sharepoint_files,
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
                'uploaded_at' => self::resolveUploadedAt($item) ?? now()->toIso8601String(),
            ];
        }

        $first = $meta[0];

        $todo->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? $todo->sharepoint_web_url,
            'sharepoint_item_id' => $first['item_id'] ?? $todo->sharepoint_item_id,
            'sharepoint_path' => $first['path'] ?? $todo->sharepoint_path,
            'sharepoint_files' => $meta,
            'attachments' => null,
        ])->save();

        DocumentBinaryStore::deleteLocalPaths($localPaths);

        Log::info('Task files stored on SharePoint', [
            'todo_id' => $todo->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        return $meta;
    }

    /**
     * Overwrite an existing SharePoint file (same path/name) and refresh stored metadata.
     *
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string}
     */
    public static function replaceFileBinary(Todo $todo, string $itemId, string $binary): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured.');
        }

        $files = is_array($todo->sharepoint_files) ? array_values($todo->sharepoint_files) : [];
        $index = null;
        $file = null;

        foreach ($files as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['item_id'] ?? '') === $itemId) {
                $index = $i;
                $file = $row;
                break;
            }
        }

        if ($file === null || $index === null) {
            throw new RuntimeException('SharePoint file mapping not found on this task.');
        }

        $remotePath = str_replace('\\', '/', (string) ($file['path'] ?? ''));
        $remoteName = filled($file['name'] ?? null)
            ? (string) $file['name']
            : basename($remotePath);

        if ($remoteName === '' || $remotePath === '') {
            throw new RuntimeException('SharePoint file path is missing.');
        }

        $folderPath = trim(dirname($remotePath), '/.');
        if ($folderPath === '.' || $folderPath === '') {
            $folderPath = self::folderPathForTask($todo, $settings);
        }

        $client = SharepointGraphClient::make($settings);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);

        $updated = [
            'name' => $remoteName,
            'path' => ($folderPath !== '' ? $folderPath.'/' : '').$remoteName,
            'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : $itemId,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : ($file['web_url'] ?? null),
            'uploaded_at' => filled($file['uploaded_at'] ?? null)
                ? (string) $file['uploaded_at']
                : (self::resolveUploadedAt($item) ?? now()->toIso8601String()),
        ];

        $files[$index] = $updated;
        $first = $files[0];

        $todo->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? $todo->sharepoint_web_url,
            'sharepoint_item_id' => $first['item_id'] ?? $todo->sharepoint_item_id,
            'sharepoint_path' => $first['path'] ?? $todo->sharepoint_path,
            'sharepoint_files' => $files,
        ])->save();

        return $updated;
    }

    /**
     * Replace sharepoint_files with the given kept list (e.g. after removals in quick view).
     *
     * @param  list<array{name?: string, path?: string, item_id?: ?string, web_url?: ?string}>  $files
     */
    public static function replaceSharepointFiles(Todo $todo, array $files): void
    {
        $meta = array_values(array_filter(
            $files,
            fn ($row): bool => is_array($row) && filled($row['path'] ?? null),
        ));

        $first = $meta[0] ?? null;

        $todo->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? null,
            'sharepoint_item_id' => $first['item_id'] ?? null,
            'sharepoint_path' => $first['path'] ?? null,
            'sharepoint_files' => $meta !== [] ? $meta : null,
            'attachments' => null,
        ])->save();
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

    /**
     * @param  array<string, mixed>  $item
     */
    public static function resolveUploadedAt(array $item): ?string
    {
        foreach (['createdDateTime', 'lastModifiedDateTime', 'fileSystemInfo.createdDateTime'] as $key) {
            if (str_contains($key, '.')) {
                [$parent, $child] = explode('.', $key, 2);
                $value = $item[$parent][$child] ?? null;
            } else {
                $value = $item[$key] ?? null;
            }

            if (filled($value)) {
                try {
                    return \Illuminate\Support\Carbon::parse((string) $value)->toIso8601String();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Fill missing uploaded_at values from Microsoft Graph (once) and persist.
     */
    public static function backfillUploadedAt(Todo $todo): void
    {
        if (! is_array($todo->sharepoint_files) || $todo->sharepoint_files === []) {
            return;
        }

        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            return;
        }

        $client = SharepointGraphClient::make($settings);
        $files = array_values($todo->sharepoint_files);
        $changed = false;

        foreach ($files as $i => $row) {
            if (! is_array($row) || filled($row['uploaded_at'] ?? null)) {
                continue;
            }

            $itemId = (string) ($row['item_id'] ?? '');
            if ($itemId === '') {
                continue;
            }

            try {
                $item = $client->getItem($itemId);
                $uploadedAt = self::resolveUploadedAt($item);
                if ($uploadedAt === null) {
                    continue;
                }
                $files[$i]['uploaded_at'] = $uploadedAt;
                $changed = true;
            } catch (\Throwable) {
                continue;
            }
        }

        if (! $changed) {
            return;
        }

        $todo->forceFill(['sharepoint_files' => $files])->save();
    }
}
