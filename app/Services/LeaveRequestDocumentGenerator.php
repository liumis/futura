<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveRequestCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LeaveRequestDocumentGenerator
{
    public static function ensureDocumentTypesExist(): void
    {
        foreach (LeaveRequestCatalog::names() as $name) {
            DocumentType::query()->firstOrCreate(
                ['name' => $name],
                ['group_by_year' => true],
            );
        }
    }

    public static function syncFor(LeaveRequest $leave, ?User $creator = null): Document
    {
        if (filled($leave->document_id) && $leave->document !== null) {
            $document = self::regenerateFor($leave);
            ActivityLogger::logReportGenerated('Leave request Prašymas PDF', 'pdf', $leave, [
                'document_id' => $document->getKey(),
                'mode' => 'regenerated',
            ]);

            return $document;
        }

        $document = self::createFor($leave, $creator);
        ActivityLogger::logReportGenerated('Leave request Prašymas PDF', 'pdf', $leave, [
            'document_id' => $document->getKey(),
            'mode' => 'created',
        ]);

        return $document;
    }

    public static function createFor(LeaveRequest $leave, ?User $creator = null): Document
    {
        self::ensureDocumentTypesExist();

        $leave->loadMissing(['employee', 'leaveRequestType']);

        [$type, $documentName, $binary, $fileName] = self::buildPayload($leave);
        $creatorId = $creator?->getKey() ?? auth()->id();

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $creatorId,
            'flag_approved' => false,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        self::upload($leave, $document, $binary, $fileName);

        $leave->forceFill([
            'document_id' => $document->getKey(),
        ])->saveQuietly();

        return $document->fresh(['documentType']);
    }

    public static function regenerateFor(LeaveRequest $leave): Document
    {
        self::ensureDocumentTypesExist();

        $leave->loadMissing(['employee', 'leaveRequestType', 'document.documentType']);

        $document = $leave->document;

        if ($document === null) {
            return self::createFor($leave, auth()->user());
        }

        [$type, $documentName, $binary, $fileName] = self::buildPayload($leave);

        $document->forceFill([
            'document_date' => now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ])->save();

        self::upload($leave, $document->fresh(['documentType']), $binary, $fileName);

        return $document->fresh(['documentType']);
    }

    /**
     * @return array{0: DocumentType, 1: string, 2: string, 3: string}
     */
    protected static function buildPayload(LeaveRequest $leave): array
    {
        $typeName = $leave->leaveRequestType?->name ?: 'Kita';
        $type = DocumentType::query()->firstOrCreate(
            ['name' => $typeName],
            ['group_by_year' => true],
        );

        $employeeName = $leave->employee?->fullName() ?: ('#'.$leave->employee_id);
        $documentName = sprintf(
            'Prašymas — %s — %s',
            $typeName,
            $employeeName,
        );

        $binary = LeaveRequestPdfGenerator::generate($leave);
        $safeType = Str::slug($typeName, '-');
        $fileName = sprintf(
            'prasymas-%s-%s.pdf',
            $safeType !== '' ? $safeType : 'leave',
            $leave->getKey(),
        );

        return [$type, $documentName, $binary, $fileName];
    }

    protected static function upload(LeaveRequest $leave, Document $document, string $binary, string $fileName): void
    {
        try {
            DocumentBinaryStore::storeBinary($document, $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning('Leave request document SharePoint upload failed', [
                'leave_request_id' => $leave->getKey(),
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Prašymas PDF storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
