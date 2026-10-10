<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeMonthlyPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PayrollAccrualJournalDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Darbo užmokesčio priskaitymo žiniaraštis';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>|list<EmployeeMonthlyPayment>|null  $payments
     */
    public static function createForMonth(int $year, int $month, Collection|array|null $payments = null, ?User $actor = null): Document
    {
        $actor ??= auth()->user();
        $period = Carbon::create($year, $month, 1);

        if ($payments === null) {
            $payments = EmployeeMonthlyPayment::query()
                ->with('employee')
                ->whereDate('payment_date', '>=', $period->copy()->startOfMonth()->toDateString())
                ->whereDate('payment_date', '<=', $period->copy()->endOfMonth()->toDateString())
                ->orderBy('payment_date')
                ->orderBy('id')
                ->get();
        } else {
            $payments = collect($payments)
                ->filter(fn ($payment): bool => $payment instanceof EmployeeMonthlyPayment)
                ->values();
        }

        if ($payments->isEmpty()) {
            throw new RuntimeException(sprintf('No payments for %04d-%02d to build the payroll journal.', $year, $month));
        }

        $binary = PayrollAccrualJournalPdfGenerator::generate($payments, $year, $month);
        $type = self::ensureDocumentTypeExists();
        $documentName = sprintf(
            'Darbo užmokesčio priskaitymo žiniaraštis — %s %d',
            PayrollAccrualJournalPdfGenerator::MONTH_NAMES_GENITIVE_LT[$month] ?? $period->format('F'),
            $year,
        );
        $fileName = sprintf('darbo-uzmokescio-priskaitymo-ziniarastis-%04d-%02d.pdf', $year, $month);

        $existing = Document::query()
            ->where('document_type_id', $type->getKey())
            ->whereDate('document_date', $period->copy()->endOfMonth()->toDateString())
            ->where('name', $documentName)
            ->first();

        if ($existing instanceof Document) {
            $existing->forceFill([
                'content_hash' => hash('sha256', $binary),
                'pdf_hash' => hash('sha256', $binary),
                'flag_approved' => true,
                'approval_date' => now(),
                'user_approved_id' => $actor instanceof User ? $actor->getKey() : $existing->user_approved_id,
            ])->save();

            DocumentBinaryStore::storeBinary($existing->fresh(['documentType']), $binary, $fileName);

            return $existing->fresh(['documentType']);
        }

        $document = Document::query()->create([
            'document_date' => $period->copy()->endOfMonth(),
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
            Log::warning('Payroll accrual journal SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'year' => $year,
                'month' => $month,
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'Payroll journal storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $document = $document->fresh(['documentType']);

        ActivityLogger::logReportGenerated('Payroll accrual journal PDF', 'pdf', $document, [
            'document_id' => $document->getKey(),
            'year' => $year,
            'month' => $month,
            'payment_count' => $payments->count(),
        ]);

        return $document;
    }
}
