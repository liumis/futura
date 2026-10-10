<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class Gpm312ReportDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'GPM312 deklaracija';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    public static function create(int $year, ?User $actor = null): Document
    {
        $actor ??= auth()->user();
        $report = Gpm312ReportBuilder::build($year);
        $binary = Gpm312PdfGenerator::generate($year);
        $type = self::ensureDocumentTypeExists();
        $documentName = self::documentName($report);
        $fileName = self::fileName($year);
        $documentDate = Carbon::create($year, 12, 31)->endOfDay();

        $document = Document::query()->create([
            'document_date' => $documentDate,
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
            Log::warning('GPM312 declaration SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'year' => $year,
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'GPM312 declaration storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = $document->fresh(['documentType']);

        ActivityLogger::logReportGenerated('GPM312 declaration PDF', 'pdf', $document, [
            'document_id' => $document->getKey(),
            'year' => $year,
            'period' => $report['period_label'],
        ]);

        return $document;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function documentName(array $report = []): string
    {
        $period = trim((string) ($report['period_label'] ?? ''));

        return $period !== ''
            ? 'GPM312 deklaracija — '.$period
            : 'GPM312 deklaracija';
    }

    public static function fileName(int $year): string
    {
        return sprintf('gpm312-%04d.pdf', $year);
    }
}
