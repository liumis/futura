<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SharepointCustomsDocumentsUploader
{
    public const FOLDER_NAME = 'customs-documents';

    /**
     * {root}/customs-documents/{YYYY}/{YYYY-MM}
     */
    public static function folderPathForCargo(Cargo $cargo, ?SharepointSetting $settings = null): string
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

        $date = $cargo->actual_date ?? $cargo->estimated_arrival ?? now();
        $year = $date->format('Y');
        $monthKey = $date->format('Y-m');

        $segments[] = self::FOLDER_NAME;
        $segments[] = $client->sanitizeFolderName($year);
        $segments[] = $client->sanitizeFolderName($monthKey);

        return implode('/', $segments);
    }

    /**
     * @param  list<string>  $localPaths
     * @param  array<string, string>  $originalNames  Stored path => original filename
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string, original_name: string, uploaded_at: string}>
     */
    public static function ingestFromLocalPaths(
        Cargo $cargo,
        array $localPaths,
        array $originalNames = [],
    ): array {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Customs documents are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path) && is_string($path)));
        $meta = $cargo->customsDocumentsList();

        if ($localPaths === []) {
            return $meta;
        }

        $folderPath = self::folderPathForCargo($cargo, $settings);
        $client = SharepointGraphClient::make($settings);

        foreach ($localPaths as $localPath) {
            if (! Storage::disk('public')->exists($localPath)) {
                throw new RuntimeException('Uploaded file is missing: '.$localPath);
            }

            $binary = Storage::disk('public')->get($localPath);

            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not read the uploaded customs document.');
            }

            $fallbackName = basename(str_replace('\\', '/', $localPath));
            $originalName = trim((string) ($originalNames[$localPath] ?? $originalNames[$fallbackName] ?? ''));

            if ($originalName === '') {
                $originalName = $fallbackName;
            }

            $remoteName = self::remoteFileName($cargo, $originalName, $meta, $client);
            $item = $client->uploadBinary($folderPath, $remoteName, $binary);
            $path = $folderPath.'/'.$remoteName;

            $meta[] = [
                'name' => $remoteName,
                'original_name' => $originalName,
                'path' => $path,
                'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
                'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        $cargo->forceFill([
            'customs_documents' => $meta,
        ])->save();

        foreach ($localPaths as $localPath) {
            self::deleteLocalPath($localPath);
        }

        Log::info('Customs documents stored on SharePoint', [
            'cargo_id' => $cargo->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        ActivityLogger::logFileUploaded('Customs documents', 'multi', $cargo, [
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
     * @param  list<array{name?: string}>  $existing
     */
    public static function remoteFileName(
        Cargo $cargo,
        string $originalName,
        array $existing = [],
        ?SharepointGraphClient $client = null,
    ): string {
        $client ??= SharepointGraphClient::make();
        $safe = $client->sanitizeFileName($originalName !== '' ? $originalName : 'document.pdf');
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'document';
        $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));

        if ($ext === '') {
            $ext = 'pdf';
        }

        $date = ($cargo->actual_date ?? $cargo->estimated_arrival ?? now())->format('Y-m-d');
        $id = filled($cargo->getKey()) ? (string) $cargo->getKey() : 'new';
        $remoteName = $id.'-'.$date.'-customs-doc-'.$base.'.'.$ext;

        $used = [];
        foreach ($existing as $row) {
            if (is_array($row) && filled($row['name'] ?? null)) {
                $used[strtolower((string) $row['name'])] = true;
            }
        }

        if (! isset($used[strtolower($remoteName)])) {
            return $remoteName;
        }

        $i = 2;
        while (isset($used[strtolower($id.'-'.$date.'-customs-doc-'.$base.'-'.$i.'.'.$ext)])) {
            $i++;
        }

        return $id.'-'.$date.'-customs-doc-'.$base.'-'.$i.'.'.$ext;
    }

    private static function deleteLocalPath(string $localPath): void
    {
        try {
            Storage::disk('public')->delete($localPath);
        } catch (\Throwable) {
            // Local cleanup is best-effort.
        }
    }
}
