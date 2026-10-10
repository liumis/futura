<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementTransaction;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeePaymentReport;
use App\Services\Fr0600ReportBuilder;
use App\Services\Fr0600ReportDocumentGenerator;
use App\Services\Gpm313ReportDocumentGenerator;
use App\Services\PayrollAuthoritySepaExporter;
use App\Services\SamReportDocumentGenerator;
use Illuminate\Support\Carbon;
use RuntimeException;

class BankStatementDocumentMatcher
{
    public static function isVmiGpmTransfer(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
            $transaction->counterpart_iban,
        ]))));

        if ($haystack === '') {
            return false;
        }

        // Explicit PVM / non-GPM tax remittances (code 1001) — handled by FR0600 matcher.
        if (self::looksLikePvmRemittance($haystack)) {
            return false;
        }

        $code = PayrollAuthoritySepaExporter::VMI_PAYMENT_CODE;
        if (preg_match('/\b'.$code.'\b/u', $haystack) === 1 || str_contains($haystack, 'GPM')) {
            return true;
        }

        $isVmi = str_contains($haystack, 'VMI')
            || str_contains($haystack, 'VALSTYBIN')
            || str_contains($haystack, 'MOKESČIŲ INSPEK')
            || str_contains($haystack, 'MOKESCIU INSPEK')
            || str_contains($haystack, (string) PayrollAuthoritySepaExporter::DEFAULT_VMI_IBAN);

        if (! $isVmi) {
            return false;
        }

        // Bulk "administruojami mokesčiai" without 1311/GPM is not employment GPM.
        if (str_contains($haystack, 'ADMINISTRUOJAMI') || str_contains($haystack, 'DEKLARUOJAMI')) {
            return false;
        }

        return self::suggestionGpm313For($transaction) !== null;
    }

    public static function isVmiPvmTransfer(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
            $transaction->counterpart_iban,
        ]))));

        if ($haystack === '' || ! self::looksLikePvmRemittance($haystack)) {
            return false;
        }

        $isVmi = str_contains($haystack, 'VMI')
            || str_contains($haystack, 'VALSTYBIN')
            || str_contains($haystack, 'MOKESČIŲ INSPEK')
            || str_contains($haystack, 'MOKESCIU INSPEK')
            || str_contains($haystack, 'PVM')
            || str_contains($haystack, (string) PayrollAuthoritySepaExporter::DEFAULT_VMI_IBAN);

        return $isVmi || self::suggestionFr0600For($transaction) !== null;
    }

    public static function suggestionFr0600For(BankStatementTransaction $transaction): ?Document
    {
        $amount = round(abs((float) $transaction->amount), 2);
        if ($amount < 0.005) {
            return null;
        }

        $booking = $transaction->booking_date instanceof Carbon
            ? $transaction->booking_date->copy()
            : Carbon::parse((string) $transaction->booking_date);

        $typeId = DocumentType::query()
            ->where('name', Fr0600ReportDocumentGenerator::DOCUMENT_TYPE_NAME)
            ->value('id');

        if ($typeId === null) {
            return null;
        }

        // VAT for month M is usually paid in M+1 (sometimes M+2). Try prior months first.
        for ($offset = 1; $offset <= 3; $offset++) {
            $period = $booking->copy()->subMonthsNoOverflow($offset);
            $year = (int) $period->year;
            $month = (int) $period->month;
            $report = Fr0600ReportBuilder::build($year, $month);
            $payable = round(abs((float) ($report['fields']['36'] ?? 0)), 2);

            if ($payable < 0.005 || abs($payable - $amount) >= 0.015) {
                continue;
            }

            $documentDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            $document = Document::query()
                ->where('document_type_id', $typeId)
                ->whereDate('document_date', $documentDate)
                ->orderByDesc('id')
                ->first();

            if ($document instanceof Document) {
                return $document;
            }
        }

        return null;
    }

    private static function looksLikePvmRemittance(string $haystack): bool
    {
        return preg_match('/\bPVM\b|\b1001\b|\bFR0600\b/u', $haystack) === 1;
    }

    public static function suggestionGpm313For(BankStatementTransaction $transaction): ?Document
    {
        return self::suggestionAuthorityDocumentFor(
            $transaction,
            Gpm313ReportDocumentGenerator::DOCUMENT_TYPE_NAME,
            fn (EmployeeMonthlyPayment $payment): float => round((float) ($payment->gpm_amount ?? 0), 2),
        );
    }

    public static function isSodraSamTransfer(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
            $transaction->counterpart_iban,
        ]))));

        if ($haystack === '') {
            return false;
        }

        $code = PayrollAuthoritySepaExporter::SODRA_PAYMENT_CODE;
        if (preg_match('/\b'.$code.'\b/u', $haystack) === 1) {
            return true;
        }

        $isSodra = str_contains($haystack, 'SODRA')
            || str_contains($haystack, 'VSDFV')
            || str_contains($haystack, 'SADM')
            || str_contains($haystack, (string) PayrollAuthoritySepaExporter::DEFAULT_SODRA_IBAN);

        if (! $isSodra) {
            return false;
        }

        return self::suggestionSamFor($transaction) !== null;
    }

    public static function suggestionSamFor(BankStatementTransaction $transaction): ?Document
    {
        return self::suggestionAuthorityDocumentFor(
            $transaction,
            SamReportDocumentGenerator::DOCUMENT_TYPE_NAME,
            fn (EmployeeMonthlyPayment $payment): float => round(
                (float) ($payment->sodra_employee_amount ?? 0) + (float) ($payment->sodra_employer_amount ?? 0),
                2,
            ),
        );
    }

    /**
     * @param  callable(EmployeeMonthlyPayment): float  $amountFromPayment
     */
    private static function suggestionAuthorityDocumentFor(
        BankStatementTransaction $transaction,
        string $documentTypeName,
        callable $amountFromPayment,
    ): ?Document {
        $amount = round(abs((float) $transaction->amount), 2);
        if ($amount < 0.005) {
            return null;
        }

        $booking = $transaction->booking_date instanceof Carbon
            ? $transaction->booking_date->copy()
            : Carbon::parse((string) $transaction->booking_date);

        $typeId = DocumentType::query()
            ->where('name', $documentTypeName)
            ->value('id');

        if ($typeId === null) {
            return null;
        }

        $payments = EmployeeMonthlyPayment::query()
            ->get(['payment_date', 'gpm_amount', 'sodra_employee_amount', 'sodra_employer_amount', 'comment']);

        // Prefer DU-journal / month-end payroll totals so mid-month advances don't inflate the match.
        $totalsByMonth = $payments
            ->groupBy(fn (EmployeeMonthlyPayment $payment): string => $payment->payment_date?->format('Y-m') ?? '')
            ->map(function ($group) use ($amountFromPayment): array {
                $all = round((float) $group->sum(fn (EmployeeMonthlyPayment $payment): float => $amountFromPayment($payment)), 2);
                $journal = round((float) $group
                    ->filter(function (EmployeeMonthlyPayment $payment): bool {
                        $comment = mb_strtolower((string) ($payment->comment ?? ''));
                        $day = (int) ($payment->payment_date?->day ?? 0);
                        $end = (int) ($payment->payment_date?->copy()->endOfMonth()->day ?? 0);

                        return str_contains($comment, 'imported from du journal')
                            || ($end > 0 && $day === $end);
                    })
                    ->sum(fn (EmployeeMonthlyPayment $payment): float => $amountFromPayment($payment)), 2);

                return array_values(array_unique(array_filter([$journal, $all], fn (float $v): bool => $v > 0.005)));
            })
            ->map(function (array $candidates, string $key) use ($amount): ?float {
                if ($key === '') {
                    return null;
                }
                foreach ($candidates as $total) {
                    if (abs($total - $amount) < 0.015) {
                        return $total;
                    }
                }

                return null;
            })
            ->filter(fn (?float $total): bool => $total !== null)
            ->sortKeysDesc();

        foreach ($totalsByMonth as $ym => $total) {
            [$year, $month] = array_map('intval', explode('-', (string) $ym));
            $accrualEnd = Carbon::create($year, $month, 1)->endOfMonth();

            if ($booking->lt($accrualEnd->copy()->startOfMonth())
                || $booking->gt($accrualEnd->copy()->addDays(45))) {
                continue;
            }

            $document = Document::query()
                ->where('document_type_id', $typeId)
                ->whereDate('document_date', $accrualEnd->toDateString())
                ->orderByDesc('id')
                ->first();

            if ($document instanceof Document) {
                return $document;
            }
        }

        $prior = $booking->copy()->subMonthNoOverflow();
        $priorPayments = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $prior->copy()->startOfMonth()->toDateString())
            ->whereDate('payment_date', '<=', $prior->copy()->endOfMonth()->toDateString())
            ->get(['gpm_amount', 'sodra_employee_amount', 'sodra_employer_amount']);

        $priorTotal = round((float) $priorPayments->sum(fn (EmployeeMonthlyPayment $payment): float => $amountFromPayment($payment)), 2);

        if (abs($priorTotal - $amount) < 0.015) {
            return Document::query()
                ->where('document_type_id', $typeId)
                ->whereDate('document_date', $prior->copy()->endOfMonth()->toDateString())
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    public static function link(BankStatementTransaction $transaction, Document $document): void
    {
        if ((int) $transaction->document_id === (int) $document->getKey()) {
            return;
        }

        if ($transaction->document_id !== null) {
            throw new RuntimeException('This bank payment is already linked to another document.');
        }

        self::clearOtherLinks($transaction);

        $transaction->forceFill([
            'document_id' => $document->getKey(),
            'invoice_id' => null,
            'payment_id' => null,
            'employee_monthly_payment_id' => null,
            'business_trip_request_id' => null,
        ])->save();
    }

    public static function unlink(BankStatementTransaction $transaction): void
    {
        if (! $transaction->document_id) {
            return;
        }

        $transaction->update([
            'document_id' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function documentPickerOptions(): array
    {
        return Document::query()
            ->with('documentType')
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (Document $document): array => [
                $document->getKey() => self::documentLabel($document),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function searchDocuments(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return Document::query()
            ->with('documentType')
            ->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('documentType', function ($type) use ($search): void {
                        $type->where('name', 'like', '%'.$search.'%');
                    });
            })
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (Document $document): array => [
                $document->getKey() => self::documentLabel($document),
            ])
            ->all();
    }

    public static function documentLabel(Document $document): string
    {
        $document->loadMissing('documentType');

        return implode(' · ', array_filter([
            $document->name,
            $document->documentType?->name,
            optional($document->document_date)->format('Y-m-d'),
        ]));
    }

    /**
     * @return array<int, string>
     */
    public static function reportPickerOptions(): array
    {
        return EmployeePaymentReport::query()
            ->with('document')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (EmployeePaymentReport $report): array => [
                $report->getKey() => self::reportLabel($report),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function searchReports(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return EmployeePaymentReport::query()
            ->with('document')
            ->where('name', 'like', '%'.$search.'%')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (EmployeePaymentReport $report): array => [
                $report->getKey() => self::reportLabel($report),
            ])
            ->all();
    }

    public static function reportLabel(EmployeePaymentReport $report): string
    {
        return implode(' · ', array_filter([
            $report->name,
            $report->status?->label(),
            $report->document?->name,
        ]));
    }

    public static function documentFromReport(EmployeePaymentReport $report): Document
    {
        $report->loadMissing('document');

        if (! $report->document instanceof Document) {
            throw new RuntimeException('This payment report has no signed document yet.');
        }

        return $report->document;
    }

    private static function clearOtherLinks(BankStatementTransaction $transaction): void
    {
        if ($transaction->invoice_id || $transaction->invoices()->exists()) {
            BankStatementInvoiceMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->payment_id) {
            BankStatementPaymentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->employee_monthly_payment_id) {
            BankStatementSalaryMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->business_trip_request_id) {
            BankStatementBusinessTripMatcher::unlink($transaction);
            $transaction->refresh();
        }
    }
}
