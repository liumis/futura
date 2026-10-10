<?php

namespace App\Services;

use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\BankStatements\BankStatementDocumentMatcher;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RefundRequestDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Prašymas grąžinti';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  array{
     *     document_date?: string,
     *     counterpart_name: string,
     *     counterpart_contact?: ?string,
     *     invoice_reference: string,
     *     amount: float,
     *     currency?: string,
     *     reason?: string,
     *     bank_reference?: ?string,
     *     prepared_by?: ?string,
     * }  $payload
     */
    public static function create(array $payload, ?User $uploader = null, ?BankStatementTransaction $linkTransaction = null): Document
    {
        $type = self::ensureDocumentTypeExists();
        $company = CompanySetting::instance();
        $uploaderId = $uploader?->getKey() ?? auth()->id();

        $documentDate = filled($payload['document_date'] ?? null)
            ? (string) $payload['document_date']
            : now()->toDateString();
        $counterpartName = trim((string) $payload['counterpart_name']);
        $invoiceReference = trim((string) $payload['invoice_reference']);
        $amount = round((float) $payload['amount'], 2);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'EUR'))) ?: 'EUR';
        $reason = trim((string) ($payload['reason'] ?? 'Mokėjimas buvo atliktas į neteisingą banko sąskaitą / Payment sent to the wrong bank account.'));
        $preparedBy = trim((string) ($payload['prepared_by'] ?? $company->contact_name ?? '')) ?: 'Direktorius';

        $documentName = trim(sprintf(
            'Prašymas grąžinti — %s — %s — %s',
            $counterpartName !== '' ? $counterpartName : 'Kontrahentas',
            $invoiceReference !== '' ? $invoiceReference : 'be nr.',
            Money::format($amount, 2, $currency),
        ));

        $binary = Pdf::loadView('pdf.refund-request-prasymas', [
            'documentDate' => $documentDate,
            'companyName' => filled($company->company_name) ? trim((string) $company->company_name) : 'Futura Textiles Europe, UAB',
            'companyAddress' => $company->company_address,
            'companyId' => $company->company_id,
            'companyVat' => $company->company_vat,
            'companyEmail' => $company->company_email,
            'counterpartName' => $counterpartName !== '' ? $counterpartName : '—',
            'counterpartContact' => filled($payload['counterpart_contact'] ?? null) ? (string) $payload['counterpart_contact'] : null,
            'invoiceReference' => $invoiceReference !== '' ? $invoiceReference : '—',
            'amountFormatted' => Money::format($amount, 2, $currency),
            'reason' => $reason,
            'bankReference' => filled($payload['bank_reference'] ?? null) ? (string) $payload['bank_reference'] : null,
            'preparedBy' => $preparedBy,
        ])
            ->setPaper('a4', 'portrait')
            ->output();

        $safeCounterpart = Str::slug($counterpartName, '-') ?: 'counterpart';
        $safeInvoice = Str::slug($invoiceReference, '-') ?: 'invoice';
        $fileName = sprintf('prasymas-grazinti-%s-%s.pdf', $safeCounterpart, $safeInvoice);

        $document = Document::query()->create([
            'document_date' => $documentDate,
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $uploaderId,
            'flag_approved' => true,
            'user_approved_id' => $uploaderId,
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        try {
            DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning('Refund request document SharePoint upload failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);
            $document->forceDelete();

            throw new RuntimeException(
                'Prašymas grąžinti storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($linkTransaction instanceof BankStatementTransaction) {
            BankStatementDocumentMatcher::link($linkTransaction->fresh() ?? $linkTransaction, $document->fresh());
        }

        return $document->fresh(['documentType']);
    }

    public static function createForBankRefund(BankStatementTransaction $transaction, ?User $uploader = null): Document
    {
        $invoiceReference = self::guessInvoiceReference($transaction) ?? '—';
        $counterpart = trim((string) ($transaction->counterpart_name ?? '')) ?: 'Kontrahentas';

        return self::create([
            'document_date' => optional($transaction->booking_date)->toDateString() ?? now()->toDateString(),
            'counterpart_name' => $counterpart,
            'counterpart_contact' => null,
            'invoice_reference' => $invoiceReference,
            'amount' => abs((float) $transaction->amount),
            'currency' => $transaction->currency ?: 'EUR',
            'reason' => 'Mokėjimas buvo atliktas į neteisingą banko sąskaitą; lėšos grąžinamos / Payment sent to the wrong bank account; funds are refunded.',
            'bank_reference' => trim((string) ($transaction->description ?? '')) ?: null,
            'prepared_by' => CompanySetting::instance()->contact_name ?: 'Deividas Arman',
        ], $uploader, $transaction);
    }

    public static function guessInvoiceReference(BankStatementTransaction $transaction): ?string
    {
        $haystack = trim(implode(' ', array_filter([
            $transaction->description,
            $transaction->comment,
            $transaction->counterpart_name,
        ])));

        if ($haystack === '') {
            return null;
        }

        if (preg_match('/\b(KAR\s*\d{3,}|FTE\s*\d{3,}|\d{4,})\b/iu', $haystack, $matches) === 1) {
            return strtoupper(preg_replace('/\s+/', '', $matches[1]) ?? $matches[1]);
        }

        return null;
    }
}
