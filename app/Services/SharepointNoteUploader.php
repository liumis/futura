<?php

namespace App\Services;

use App\Models\Note;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SharepointNoteUploader
{
    /**
     * Folder path: {root}/notes/{id}-{sanitizedTitle}
     */
    public static function folderPathForNote(Note $note, ?SharepointSetting $settings = null): string
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

        $segments[] = 'notes';
        $segments[] = self::noteFolderName($note, $client);

        return implode('/', $segments);
    }

    public static function noteFolderName(Note $note, ?SharepointGraphClient $client = null): string
    {
        $client ??= SharepointGraphClient::make();
        $title = filled($note->title) ? (string) $note->title : 'note';
        $safeTitle = $client->sanitizeFolderName($title);

        return $note->getKey().'-'.$safeTitle;
    }

    /**
     * @param  list<string>  $localPaths
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string}>
     */
    public static function ingestLocalUploads(Note $note, array $localPaths): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Note files are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        if (! $note->exists) {
            throw new RuntimeException('Save the note before uploading files to SharePoint.');
        }

        $folderPath = self::folderPathForNote($note, $settings);
        $client = SharepointGraphClient::make($settings);

        $existing = [];
        if (is_array($note->sharepoint_files)) {
            $existing = array_values(array_filter(
                $note->sharepoint_files,
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

        $note->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? $note->sharepoint_web_url,
            'sharepoint_item_id' => $first['item_id'] ?? $note->sharepoint_item_id,
            'sharepoint_path' => $first['path'] ?? $note->sharepoint_path,
            'sharepoint_files' => $meta,
            'attachments' => null,
        ])->save();

        DocumentBinaryStore::deleteLocalPaths($localPaths);

        Log::info('Note files stored on SharePoint', [
            'note_id' => $note->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        ActivityLogger::logFileUploaded('Note attachments', 'multi', $note, [
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
