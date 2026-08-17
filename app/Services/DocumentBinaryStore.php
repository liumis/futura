<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentBinaryStore
{
    /**
     * Whether the document has a retrievable file (SharePoint or legacy local).
     */
    public static function hasFile(Document $document): bool
    {
        if ($document->hasSharepointLink()) {
            return true;
        }

        $path = $document->displayFilePath() ?: $document->file_path;

        return filled($path) && Storage::disk('public')->exists((string) $path);
    }

    public static function getBinary(Document $document): string
    {
        if (filled($document->sharepoint_item_id)) {
            $binary = SharepointGraphClient::make()->downloadItemContent((string) $document->sharepoint_item_id);
            if ($binary !== '') {
                return $binary;
            }

            throw new RuntimeException('SharePoint returned an empty file.');
        }

        $path = $document->displayFilePath() ?: $document->file_path;
        if (! filled($path) || ! Storage::disk('public')->exists((string) $path)) {
            throw new RuntimeException('Document file is missing.');
        }

        $binary = Storage::disk('public')->get((string) $path);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Could not read the document file.');
        }

        return $binary;
    }

    public static function downloadFileName(Document $document): string
    {
        if (filled($document->sharepoint_path)) {
            return basename(str_replace('\\', '/', (string) $document->sharepoint_path));
        }

        $path = $document->displayFilePath() ?: $document->file_path;
        if (filled($path)) {
            return basename(str_replace('\\', '/', (string) $path));
        }

        $base = filled($document->name) ? (string) $document->name : 'document';

        return SharepointGraphClient::make()->sanitizeFileName($base).'.bin';
    }

    public static function downloadResponse(Document $document): StreamedResponse
    {
        $binary = self::getBinary($document);
        $fileName = self::downloadFileName($document);
        $mime = self::guessMime($fileName);

        ActivityLogger::logReportDownloaded(
            'Document file',
            pathinfo($fileName, PATHINFO_EXTENSION) ?: 'bin',
            $document,
            ['file_name' => $fileName],
        );

        return response()->streamDownload(function () use ($binary): void {
            echo $binary;
        }, $fileName, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Upload binary to SharePoint, persist metadata, delete any local document files.
     *
     * @return array{path: string, item: array<string, mixed>, web_url: ?string}
     */
    public static function storeBinary(Document $document, string $binary, ?string $fileName = null): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Documents are stored only on SharePoint.');
        }

        $document->loadMissing('documentType');

        $folderPath = SharepointDocumentUploader::folderPathForDocument($document, $settings);
        $remoteName = $fileName
            ? SharepointGraphClient::make($settings)->sanitizeFileName($fileName)
            : SharepointDocumentUploader::remoteFileName($document, ((string) ($document->name ?: 'document')).'.pdf');

        if (! str_contains($remoteName, '.')) {
            $remoteName .= '.bin';
        }

        $client = SharepointGraphClient::make($settings);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);
        $path = $folderPath.'/'.$remoteName;

        $localPaths = array_values(array_filter([
            $document->file_path,
            $document->approved_file_path,
        ], fn ($p): bool => filled($p)));

        $fileMeta = [[
            'name' => $remoteName,
            'path' => $path,
            'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
        ]];

        $document->forceFill([
            'sharepoint_web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
            'sharepoint_item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'sharepoint_path' => $path,
            'sharepoint_files' => $fileMeta,
            'file_path' => null,
            'approved_file_path' => null,
        ])->save();

        self::deleteLocalPaths($localPaths);

        Log::info('Document stored on SharePoint only', [
            'document_id' => $document->getKey(),
            'path' => $path,
            'item_id' => $item['id'] ?? null,
        ]);

        return [
            'path' => $path,
            'item' => $item,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
        ];
    }

    /**
     * Move a freshly uploaded Filament/local file to SharePoint and remove the local copy.
     *
     * @return array{path: string, item: array<string, mixed>, web_url: ?string}
     */
    public static function ingestLocalUpload(Document $document): array
    {
        $localPath = $document->file_path;
        if (! filled($localPath) || ! Storage::disk('public')->exists((string) $localPath)) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        $files = self::ingestLocalUploads($document, [(string) $localPath]);
        $first = $files[0] ?? null;

        if ($first === null) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        return [
            'path' => $first['path'],
            'item' => [
                'id' => $first['item_id'],
                'webUrl' => $first['web_url'],
            ],
            'web_url' => $first['web_url'],
        ];
    }

    /**
     * Upload one or more local files to SharePoint using the auto filename pattern.
     *
     * @param  list<string>  $localPaths
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string}>
     */
    public static function ingestLocalUploads(Document $document, array $localPaths): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Documents are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            throw new RuntimeException('No local upload found to send to SharePoint.');
        }

        if ($document->exists && ! $document->canAttachFiles()) {
            throw new RuntimeException('Files cannot be attached after the document is approved or signed.');
        }

        $document->loadMissing('documentType');
        $folderPath = SharepointDocumentUploader::folderPathForDocument($document, $settings);
        $client = SharepointGraphClient::make($settings);

        $existing = [];
        if (is_array($document->sharepoint_files)) {
            $existing = array_values(array_filter(
                $document->sharepoint_files,
                fn ($row): bool => is_array($row) && filled($row['path'] ?? null),
            ));
        }

        $existingCount = count($existing);
        $newCount = count($localPaths);
        $total = $existingCount + $newCount;
        $meta = $existing;

        foreach ($localPaths as $index => $localPath) {
            if (! Storage::disk('public')->exists((string) $localPath)) {
                throw new RuntimeException('Uploaded file is missing: '.$localPath);
            }

            $binary = Storage::disk('public')->get((string) $localPath);
            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not read the uploaded file.');
            }

            $fileNumber = $total > 1 ? ($existingCount + $index + 1) : null;
            $remoteName = SharepointDocumentUploader::remoteFileName(
                $document,
                (string) $localPath,
                $fileNumber,
                $total,
            );

            $item = $client->uploadBinary($folderPath, $remoteName, $binary);
            $path = $folderPath.'/'.$remoteName;

            $meta[] = [
                'name' => $remoteName,
                'path' => $path,
                'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
                'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
            ];
        }

        $first = $meta[0];
        $previousLocal = array_values(array_filter([
            $document->file_path,
            $document->approved_file_path,
            ...$localPaths,
        ], fn ($p): bool => filled($p)));

        $document->forceFill([
            'sharepoint_web_url' => $first['web_url'] ?? $document->sharepoint_web_url,
            'sharepoint_item_id' => $first['item_id'] ?? $document->sharepoint_item_id,
            'sharepoint_path' => $first['path'] ?? $document->sharepoint_path,
            'sharepoint_files' => $meta,
            'file_path' => null,
            'approved_file_path' => null,
        ])->save();

        self::deleteLocalPaths($previousLocal);

        Log::info('Document files stored on SharePoint only', [
            'document_id' => $document->getKey(),
            'count' => count($meta),
            'paths' => array_column($meta, 'path'),
        ]);

        return $meta;
    }

    /**
     * @param  list<string|null>  $paths
     */
    public static function deleteLocalPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (! filled($path)) {
                continue;
            }

            try {
                if (Storage::disk('public')->exists((string) $path)) {
                    Storage::disk('public')->delete((string) $path);
                }
            } catch (Throwable $exception) {
                Log::warning('Failed deleting local document file', [
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    public static function guessMime(string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    public static function isPdf(Document $document, string $binary): bool
    {
        $name = self::downloadFileName($document);
        if (str_ends_with(strtolower($name), '.pdf')) {
            return true;
        }

        return str_starts_with($binary, '%PDF');
    }
}
