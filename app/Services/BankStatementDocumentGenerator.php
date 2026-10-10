<?php

namespace App\Services;

use App\Models\BankStatementImport;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class BankStatementDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Banko išrašas';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * Create (or refresh) a Documents entry for a bank statement import that
     * already has a SharePoint file. Does not re-upload the file.
     */
    public static function syncForImport(BankStatementImport $import, ?User $actor = null): ?Document
    {
        if (! $import->hasSharepointLink()) {
            return null;
        }

        $import->loadMissing('document');
        $actor ??= auth()->user();
        $type = self::ensureDocumentTypeExists();

        $remoteName = filled($import->sharepoint_path)
            ? basename(str_replace('\\', '/', (string) $import->sharepoint_path))
            : $import->displayName();

        $documentName = self::documentNameFor($import);
        $documentDate = $import->period_to
            ?? $import->period_from
            ?? $import->created_at
            ?? now();

        $files = [[
            'name' => $remoteName,
            'path' => (string) ($import->sharepoint_path ?: $remoteName),
            'item_id' => filled($import->sharepoint_item_id) ? (string) $import->sharepoint_item_id : null,
            'web_url' => filled($import->sharepoint_web_url) ? (string) $import->sharepoint_web_url : null,
        ]];

        $payload = [
            'document_date' => $documentDate,
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'approved_file_path' => null,
            'sharepoint_web_url' => $import->sharepoint_web_url,
            'sharepoint_item_id' => $import->sharepoint_item_id,
            'sharepoint_path' => $import->sharepoint_path,
            'sharepoint_files' => $files,
            'content_hash' => $import->content_hash,
            'flag_approved' => true,
            'approval_date' => now(),
        ];

        if ($actor instanceof User) {
            $payload['user_uploaded_id'] = $actor->getKey();
            $payload['user_approved_id'] = $actor->getKey();
        } elseif (filled($import->uploaded_by)) {
            $payload['user_uploaded_id'] = $import->uploaded_by;
            $payload['user_approved_id'] = $import->uploaded_by;
        }

        $document = $import->document;

        if ($document instanceof Document) {
            $document->forceFill($payload)->save();
        } else {
            $document = Document::query()->create($payload);
            $import->forceFill([
                'document_id' => $document->getKey(),
            ])->saveQuietly();
        }

        Log::info('Bank statement document synced', [
            'import_id' => $import->getKey(),
            'document_id' => $document->getKey(),
            'sharepoint_path' => $import->sharepoint_path,
        ]);

        return $document->fresh(['documentType']);
    }

    public static function documentNameFor(BankStatementImport $import): string
    {
        $parts = array_filter([
            'Bank statement',
            filled($import->bank_name) ? (string) $import->bank_name : null,
            $import->period_from?->format('Y-m')
                ?? $import->period_to?->format('Y-m'),
        ]);

        $base = implode(' — ', $parts);
        $file = trim((string) ($import->original_filename ?: ''));

        if ($file !== '' && ! str_contains(mb_strtolower($base), mb_strtolower(pathinfo($file, PATHINFO_FILENAME)))) {
            return $base.' ('.$file.')';
        }

        return $base !== '' ? $base : ($file !== '' ? $file : 'Bank statement');
    }
}
