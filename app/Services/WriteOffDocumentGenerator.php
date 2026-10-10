<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WriteOffDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WriteOffDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Write-off document';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * Generate PDF and store it on SharePoint via Documents.
     */
    public static function storeFor(
        WriteOffDocument $writeOff,
        ?User $actor = null,
        InvoiceLanguage $language = InvoiceLanguage::Lithuanian,
    ): Document {
        $actor ??= auth()->user();
        $writeOff->loadMissing([
            'user',
            'stockManualUpdates.product.color.collection',
            'document',
        ]);

        $binary = WriteOffDocumentPdfGenerator::generate($writeOff, $language, logDownload: false);
        $type = self::ensureDocumentTypeExists();
        $documentName = sprintf('Write-off %s', $writeOff->document_number);
        $fileName = sprintf(
            'write-off-%s-%s.pdf',
            Str::slug((string) $writeOff->document_number, '-') ?: $writeOff->getKey(),
            $language->value,
        );

        $existing = $writeOff->document;

        if ($existing instanceof Document) {
            $existing->forceFill([
                'document_date' => $writeOff->document_date ?? now(),
                'name' => $documentName,
                'document_type_id' => $type->getKey(),
                'content_hash' => hash('sha256', $binary),
                'pdf_hash' => hash('sha256', $binary),
            ])->save();

            self::upload($writeOff, $existing->fresh(['documentType']), $binary, $fileName);

            return $existing->fresh(['documentType']);
        }

        $document = Document::query()->create([
            'document_date' => $writeOff->document_date ?? now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor instanceof User ? $actor->getKey() : $writeOff->user_id,
            'flag_approved' => true,
            'user_approved_id' => $actor instanceof User ? $actor->getKey() : $writeOff->user_id,
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        try {
            self::upload($writeOff, $document->fresh(['documentType']), $binary, $fileName);
        } catch (Throwable $exception) {
            $document->forceDelete();

            throw $exception;
        }

        $writeOff->forceFill([
            'document_id' => $document->getKey(),
        ])->save();

        ActivityLogger::logReportGenerated('Write-off document PDF', 'pdf', $writeOff, [
            'document_id' => $document->getKey(),
            'language' => $language->value,
            'mode' => 'created',
        ]);

        return $document->fresh(['documentType']);
    }

    private static function upload(
        WriteOffDocument $writeOff,
        Document $document,
        string $binary,
        string $fileName,
    ): void {
        try {
            DocumentBinaryStore::storeBinary($document, $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning('Write-off document SharePoint upload failed', [
                'write_off_document_id' => $writeOff->getKey(),
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Write-off document storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
