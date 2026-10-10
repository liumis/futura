<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LeaveRequestOrderDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Įsakymas';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    public static function createAndApprove(LeaveRequest $leave, ?User $approver = null): Document
    {
        $leave->loadMissing(['employee', 'leaveRequestType', 'confirmedBy', 'orderDocument.approvedBy']);

        if (filled($leave->order_document_id) && $leave->orderDocument !== null) {
            return $leave->orderDocument;
        }

        $approver ??= $leave->confirmedBy ?? auth()->user();

        if (! $approver instanceof User) {
            throw new RuntimeException('Leave request confirmer is required to create Įsakymas.');
        }

        [$documentName, $binary, $fileName] = self::buildPayload($leave);
        $type = self::ensureDocumentTypeExists();

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $approver->getKey(),
            'flag_approved' => false,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        try {
            DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning('Leave request Įsakymas SharePoint upload failed', [
                'leave_request_id' => $leave->getKey(),
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Įsakymas PDF storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = DocumentApprover::approve(
            $document->fresh(['documentType']),
            $approver,
            (string) (request()->ip() ?: '127.0.0.1'),
            substr((string) (request()->userAgent() ?: 'leave-request-confirmation'), 0, 500),
        );

        $leave->forceFill([
            'order_document_id' => $document->getKey(),
        ])->saveQuietly();

        ActivityLogger::logReportGenerated('Leave request Įsakymas PDF', 'pdf', $leave, [
            'document_id' => $document->getKey(),
            'approver_id' => $approver->getKey(),
            'mode' => 'created_and_approved',
        ]);

        return $document->fresh(['documentType', 'approvedBy']);
    }

    /**
     * Re-render the Įsakymas PDF (e.g. after DomPDF font fixes) and keep approval status.
     */
    public static function regenerateFor(LeaveRequest $leave): Document
    {
        $leave->loadMissing(['employee', 'leaveRequestType', 'confirmedBy', 'orderDocument.approvedBy', 'orderDocument.documentType']);

        $document = $leave->orderDocument;
        if ($document === null) {
            return self::createAndApprove($leave);
        }

        [$documentName, $binary, $fileName] = self::buildPayload($leave);
        $type = self::ensureDocumentTypeExists();
        $contentHash = hash('sha256', $binary);
        $storeBinary = $binary;
        $approver = $document->approvedBy ?? $leave->confirmedBy;

        if ($document->isApproved() && $approver instanceof User) {
            try {
                $storeBinary = DocumentApprover::stampPdf($binary, $approver);
                $base = pathinfo($fileName, PATHINFO_FILENAME) ?: 'document';
                $fileName = $base.'-approved.pdf';
            } catch (Throwable $exception) {
                Log::warning('Could not re-stamp leave Įsakymas; storing unstamped', [
                    'leave_request_id' => $leave->getKey(),
                    'document_id' => $document->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $document->forceFill([
            'document_date' => $document->document_date ?? now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'content_hash' => $contentHash,
            'pdf_hash' => hash('sha256', $storeBinary),
        ])->save();

        try {
            DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $storeBinary, $fileName);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Įsakymas PDF storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        ActivityLogger::logReportGenerated('Leave request Įsakymas PDF', 'pdf', $leave, [
            'document_id' => $document->getKey(),
            'mode' => 'regenerated',
        ]);

        return $document->fresh(['documentType', 'approvedBy']);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected static function buildPayload(LeaveRequest $leave): array
    {
        $employeeName = $leave->employee?->fullName() ?: ('#'.$leave->employee_id);
        $leaveTypeName = $leave->leaveRequestType?->name ?: 'Atostogos';
        $documentName = sprintf('Įsakymas — %s — %s', $leaveTypeName, $employeeName);
        $binary = LeaveRequestOrderPdfGenerator::generate($leave);
        $safeType = Str::slug($leaveTypeName, '-');
        $fileName = sprintf(
            'isakymas-%s-%s.pdf',
            $safeType !== '' ? $safeType : 'leave',
            $leave->getKey(),
        );

        return [$documentName, $binary, $fileName];
    }
}
