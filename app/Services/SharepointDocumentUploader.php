<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SharepointDocumentUploader
{
    /**
     * Ingest a local Filament upload into SharePoint and delete the local file.
     *
     * @return array{path: string, item: array<string, mixed>, web_url: ?string}|null
     */
    public static function uploadIfReady(Document $document): ?array
    {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady()) {
            Log::info('SharePoint upload skipped — integration not ready', [
                'document_id' => $document->getKey(),
                'enabled' => $settings->is_enabled,
                'has_site' => filled($settings->site_url) || filled($settings->site_id),
            ]);

            return null;
        }

        return self::upload($document, $settings);
    }

    /**
     * @return array{path: string, item: array<string, mixed>, web_url: ?string}
     */
    public static function upload(Document $document, ?SharepointSetting $settings = null): array
    {
        $settings ??= SharepointSetting::instance();

        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Documents are stored only on SharePoint.');
        }

        return DocumentBinaryStore::ingestLocalUpload($document->fresh(['documentType']));
    }

    public static function folderPathForDocument(Document $document, ?SharepointSetting $settings = null): string
    {
        $settings ??= SharepointSetting::instance();
        $client = SharepointGraphClient::make($settings);
        $document->loadMissing('documentType');

        $type = $document->documentType;
        $typeName = filled($type?->name) ? (string) $type->name : 'Uncategorized';

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

        $segments[] = 'documents';
        $segments[] = $client->sanitizeFolderName($typeName);

        if ($type?->group_by_year) {
            $year = $document->document_date?->format('Y') ?: now()->format('Y');
            $segments[] = $year;
        }

        return implode('/', $segments);
    }

    /**
     * @deprecated Use folderPathForDocument()
     */
    public static function folderPathForType(string $documentTypeName, ?SharepointSetting $settings = null): string
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

        $segments[] = 'documents';
        $segments[] = $client->sanitizeFolderName($documentTypeName);

        return implode('/', $segments);
    }

    /**
     * Build the auto filename stem: {id}-{Y-m-d}-{name}[-{nr}].
     * NR is appended only when more than one file is attached.
     */
    public static function generatedFileStem(
        Document|int|string|null $documentId,
        ?\DateTimeInterface $documentDate,
        ?string $documentName,
        ?int $fileNumber = null,
        int $totalFiles = 1,
    ): string {
        $idValue = $documentId instanceof Document ? $documentId->getKey() : $documentId;
        $id = filled($idValue) ? (string) $idValue : '…';

        $date = $documentDate?->format('Y-m-d') ?: now()->format('Y-m-d');

        $name = filled($documentName) ? (string) $documentName : 'document';
        $name = SharepointGraphClient::make()->sanitizeFileName($name);
        $name = pathinfo($name, PATHINFO_FILENAME) ?: 'document';

        $stem = $id.'-'.$date.'-'.$name;

        if ($totalFiles > 1 && $fileNumber !== null) {
            $stem .= '-'.$fileNumber;
        }

        return $stem;
    }

    /**
     * Human-readable preview for the Filename form field.
     */
    public static function filenamePreview(
        Document|int|string|null $documentId,
        mixed $documentDate,
        ?string $documentName,
        int $fileCount = 1,
    ): string {
        $date = null;
        if ($documentDate instanceof \DateTimeInterface) {
            $date = $documentDate;
        } elseif (filled($documentDate)) {
            try {
                $date = \Carbon\Carbon::parse($documentDate);
            } catch (\Throwable) {
                $date = null;
            }
        }

        $stem = self::generatedFileStem($documentId, $date, $documentName);

        if ($fileCount <= 1) {
            return $stem.'.[ext]';
        }

        $examples = [];
        for ($i = 1; $i <= min($fileCount, 3); $i++) {
            $examples[] = self::generatedFileStem($documentId, $date, $documentName, $i, $fileCount).'.[ext]';
        }

        if ($fileCount > 3) {
            $examples[] = '…';
        }

        return implode(', ', $examples);
    }

    public static function remoteFileName(
        Document $document,
        string $localPath,
        ?int $fileNumber = null,
        int $totalFiles = 1,
    ): string {
        $original = basename(str_replace('\\', '/', $localPath));
        $extension = pathinfo($original, PATHINFO_EXTENSION);

        $base = self::generatedFileStem(
            $document,
            $document->document_date,
            filled($document->name) ? (string) $document->name : pathinfo($original, PATHINFO_FILENAME),
            $fileNumber,
            $totalFiles,
        );

        if ($extension !== '') {
            return $base.'.'.strtolower($extension);
        }

        return $base;
    }

    public static function uploadQuietly(Document $document): void
    {
        try {
            $result = self::uploadIfReady($document);
            if ($result === null) {
                return;
            }

            Log::info('Document uploaded to SharePoint', [
                'document_id' => $document->getKey(),
                'path' => $result['path'],
                'item_id' => $result['item']['id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('SharePoint document upload failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
