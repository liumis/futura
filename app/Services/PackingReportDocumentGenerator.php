<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PackingReportDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Packing report';

    public const AUDIENCE_INTERNAL = 'internal';

    public const AUDIENCE_EXPORT = 'export';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    public static function normalizeAudience(mixed $value): string
    {
        return match ((string) $value) {
            'yes', self::AUDIENCE_EXPORT => self::AUDIENCE_EXPORT,
            'no', self::AUDIENCE_INTERNAL => self::AUDIENCE_INTERNAL,
            default => 'all',
        };
    }

    public static function audienceLabel(string $audience): string
    {
        return match (self::normalizeAudience($audience)) {
            self::AUDIENCE_EXPORT => 'Export',
            self::AUDIENCE_INTERNAL => 'Internal',
            default => 'All customers',
        };
    }

    /**
     * @param  iterable<int, Order>  $orders
     * @param  array{
     *     from?: string|null,
     *     until?: string|null,
     *     package_label?: string,
     *     period_label?: string,
     *     audience?: string,
     *     audience_label?: string
     * }  $filters
     */
    public static function create(iterable $orders, array $filters = [], ?User $actor = null): Document
    {
        $actor ??= auth()->user();
        $filters['audience'] = self::normalizeAudience($filters['audience'] ?? 'all');
        $filters['audience_label'] = $filters['audience_label'] ?? self::audienceLabel($filters['audience']);

        $binary = PackingReportPdfGenerator::generate($orders, $filters);
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
            Log::warning('Packing report SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'audience' => $filters['audience'],
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'Packing report storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = $document->fresh(['documentType']);

        ActivityLogger::logReportGenerated('Packing report PDF', 'pdf', $document, [
            'document_id' => $document->getKey(),
            'period' => $filters['period_label'] ?? null,
            'audience' => $filters['audience'],
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function documentName(array $filters = []): string
    {
        $audience = self::audienceLabel((string) ($filters['audience'] ?? 'all'));
        $period = trim((string) ($filters['period_label'] ?? ''));
        $package = trim((string) ($filters['package_label'] ?? ''));

        $parts = ['Packing report', $audience];
        if ($period !== '') {
            $parts[] = $period;
        }
        if ($package !== '' && $package !== 'All package profiles') {
            $parts[] = $package;
        }

        return implode(' — ', $parts);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fileName(array $filters = []): string
    {
        $audience = self::normalizeAudience($filters['audience'] ?? 'all');
        $from = self::dateSlug($filters['from'] ?? null) ?: 'all';
        $until = self::dateSlug($filters['until'] ?? null) ?: 'all';

        return sprintf('packing-report-%s-%s-%s.pdf', $audience, $from, $until);
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
