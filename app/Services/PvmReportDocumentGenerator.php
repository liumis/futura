<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PvmReportDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'PVM report';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @param  array<string, mixed>  $filters
     */
    public static function create(iterable $invoices, array $filters = [], ?User $actor = null): Document
    {
        $actor ??= auth()->user();
        $binary = PvmReportPdfGenerator::generate($invoices, $filters);
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
            Log::warning('PVM report SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'PVM report storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = $document->fresh(['documentType']);

        ActivityLogger::logReportGenerated('PVM report PDF', 'pdf', $document, [
            'document_id' => $document->getKey(),
            'period' => $filters['period_label'] ?? null,
            'company' => $filters['company_label'] ?? null,
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function documentName(array $filters = []): string
    {
        $period = trim((string) ($filters['period_label'] ?? ''));
        $company = trim((string) ($filters['company_label'] ?? ''));
        $direction = trim((string) ($filters['direction_label'] ?? ''));

        $parts = ['PVM report'];
        if ($period !== '') {
            $parts[] = $period;
        }
        if ($direction !== '' && $direction !== 'All invoices') {
            $parts[] = $direction;
        }
        if ($company !== '' && $company !== 'All companies') {
            $parts[] = $company;
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
        $direction = Str::slug((string) ($filters['direction'] ?? 'all'), '-') ?: 'all';

        return sprintf('pvm-report-%s-%s-%s.pdf', $direction, $from, $until);
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
