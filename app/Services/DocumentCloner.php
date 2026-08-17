<?php

namespace App\Services;

use App\Models\Document;
use App\Models\SharepointSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DocumentCloner
{
    /**
     * Copy a document with attachments. Approvals, signatures, and linked reports are not copied.
     */
    public static function clone(Document $source, User $actor): Document
    {
        $source->loadMissing(['documentType']);

        return DB::transaction(function () use ($source, $actor): Document {
            $clone = $source->replicate();

            $baseName = filled($source->name) ? trim((string) $source->name) : 'Document';
            if ($baseName === '') {
                $baseName = 'Document';
            }
            if (! str_ends_with(mb_strtolower($baseName), ' (copy)')) {
                $baseName .= ' (copy)';
            }

            $clone->forceFill([
                'name' => $baseName,
                'user_uploaded_id' => $actor->getKey(),
                'flag_approved' => false,
                'user_approved_id' => null,
                'approval_date' => null,
                'confirmed_ip' => null,
                'confirmed_user_agent' => null,
                'content_hash' => null,
                'pdf_hash' => null,
                'file_path' => null,
                'approved_file_path' => null,
                'sharepoint_web_url' => null,
                'sharepoint_item_id' => null,
                'sharepoint_path' => null,
                'sharepoint_files' => null,
                'deleted_at' => null,
            ])->save();

            self::copyAttachments($source, $clone);

            Log::info('Document cloned', [
                'source_id' => $source->getKey(),
                'clone_id' => $clone->getKey(),
                'actor_id' => $actor->getKey(),
            ]);

            return $clone->fresh() ?? $clone;
        });
    }

    protected static function copyAttachments(Document $source, Document $clone): void
    {
        $files = self::sourceFileDescriptors($source);
        if ($files === []) {
            return;
        }

        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured to clone document files.');
        }

        $clone->loadMissing('documentType');
        $folderPath = SharepointDocumentUploader::folderPathForDocument($clone, $settings);
        $client = SharepointGraphClient::make($settings);
        $total = count($files);
        $meta = [];

        foreach ($files as $index => $file) {
            $binary = self::downloadSourceBinary($client, $file);
            if ($binary === '') {
                throw new RuntimeException('Could not read a source file while cloning: '.($file['name'] ?? 'file'));
            }

            $fileNumber = $total > 1 ? $index + 1 : null;
            $remoteName = SharepointDocumentUploader::remoteFileName(
                $clone,
                (string) ($file['name'] ?? 'document.bin'),
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

        $clone->forceFill([
            'sharepoint_web_url' => $first['web_url'],
            'sharepoint_item_id' => $first['item_id'],
            'sharepoint_path' => $first['path'],
            'sharepoint_files' => $meta,
            'file_path' => null,
            'approved_file_path' => null,
        ])->save();
    }

    /**
     * @return list<array{name: string, item_id: ?string, local_path: ?string}>
     */
    protected static function sourceFileDescriptors(Document $source): array
    {
        $files = [];

        if (is_array($source->sharepoint_files)) {
            foreach ($source->sharepoint_files as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $itemId = filled($row['item_id'] ?? null) ? (string) $row['item_id'] : null;
                $name = filled($row['name'] ?? null)
                    ? (string) $row['name']
                    : (filled($row['path'] ?? null) ? basename((string) $row['path']) : 'document.bin');

                if ($itemId === null) {
                    continue;
                }

                $files[] = [
                    'name' => $name,
                    'item_id' => $itemId,
                    'local_path' => null,
                ];
            }
        }

        if ($files === [] && filled($source->sharepoint_item_id)) {
            $files[] = [
                'name' => filled($source->sharepoint_path)
                    ? basename(str_replace('\\', '/', (string) $source->sharepoint_path))
                    : 'document.bin',
                'item_id' => (string) $source->sharepoint_item_id,
                'local_path' => null,
            ];
        }

        if ($files === []) {
            foreach ([$source->approved_file_path, $source->file_path] as $localPath) {
                if (! filled($localPath) || ! Storage::disk('public')->exists((string) $localPath)) {
                    continue;
                }

                $files[] = [
                    'name' => basename(str_replace('\\', '/', (string) $localPath)),
                    'item_id' => null,
                    'local_path' => (string) $localPath,
                ];
            }
        }

        return $files;
    }

    /**
     * @param  array{name: string, item_id: ?string, local_path: ?string}  $file
     */
    protected static function downloadSourceBinary(SharepointGraphClient $client, array $file): string
    {
        if (filled($file['item_id'])) {
            try {
                return $client->downloadItemContent((string) $file['item_id']);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Could not download SharePoint file while cloning: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
        }

        if (filled($file['local_path']) && Storage::disk('public')->exists((string) $file['local_path'])) {
            $binary = Storage::disk('public')->get((string) $file['local_path']);

            return is_string($binary) ? $binary : '';
        }

        return '';
    }
}
