<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InvoiceCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TurnoverSheetDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Turnover sheet';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  iterable<int, InvoiceCode>  $codes
     * @param  array{
     *     from?: string|null,
     *     until?: string|null,
     *     contact_id?: int|string|null,
     *     period_label?: string,
     *     supplier_label?: string
     * }  $filters
     */
    public static function create(iterable $codes, array $filters = [], ?User $actor = null): Document
    {
        $actor ??= auth()->user();
        $binary = TurnoverSheetPdfGenerator::generate($codes, $filters);
        $type = self::ensureDocumentTypeExists();
        $documentName = self::documentName($filters);
        $fileName = self::fileName($filters);

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor instanceof User ? $actor->getKey() : null,
            'flag_approved' => true,
            'user_approved_id' => $actor instanceof User ? $actor->getKey() : null,
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        try {
            DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning('Turnover sheet SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'Turnover sheet storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = $document->fresh(['documentType']);

        ActivityLogger::logReportGenerated('Turnover sheet PDF', 'pdf', $document, [
            'document_id' => $document->getKey(),
            'period' => $filters['period_label'] ?? null,
            'supplier' => $filters['supplier_label'] ?? null,
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function documentName(array $filters = []): string
    {
        $period = trim((string) ($filters['period_label'] ?? ''));
        $supplier = trim((string) ($filters['supplier_label'] ?? ''));

        $parts = ['Turnover sheet'];
        if ($period !== '') {
            $parts[] = $period;
        }
        if ($supplier !== '' && $supplier !== 'All suppliers') {
            $parts[] = $supplier;
        }

        return implode(' — ', $parts);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fileName(array $filters = []): string
    {
        $from = self::dateSlug($filters['from'] ?? null) ?: 'all';
        $until = self::dateSlug($filters['until'] ?? null) ?: 'all';
        $supplier = Str::slug((string) ($filters['supplier_label'] ?? ''), '-');

        $base = sprintf('turnover-sheet-%s-%s', $from, $until);
        if ($supplier !== '' && $supplier !== 'all-suppliers') {
            $base .= '-'.$supplier;
        }

        return $base.'.pdf';
    }

    private static function dateSlug(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/[^0-9]/', '', $value) ?: Str::slug($value, '-');
    }
}
