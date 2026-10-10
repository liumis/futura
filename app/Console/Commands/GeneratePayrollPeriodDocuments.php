<?php

namespace App\Console\Commands;

use App\Models\BankStatementTransaction;
use App\Models\Document;
use App\Models\EmployeeMonthlyPayment;
use App\Models\User;
use App\Services\BankStatements\BankStatementDocumentMatcher;
use App\Services\Gpm313ReportDocumentGenerator;
use App\Services\HistoricalEmployeePayslipGenerator;
use App\Services\PayrollAccrualJournalDocumentGenerator;
use App\Services\SamReportBuilder;
use App\Services\SamReportDocumentGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class GeneratePayrollPeriodDocuments extends Command
{
    protected $signature = 'app:generate-payroll-period
                            {--from=2026-04 : Start month YYYY-MM}
                            {--to=2026-08 : End month YYYY-MM}
                            {--skip-payslips : Skip payslip generation}
                            {--skip-journal : Skip Darbo užmokesčio priskaitymo žiniaraštis}
                            {--skip-gpm313 : Skip GPM313 generation}
                            {--skip-sam : Skip SAM (Sodra) generation}
                            {--skip-bank-link : Skip linking bank lines to journals / GPM313 / SAM}';

    protected $description = 'Generate payslips, payroll journals, GPM313, and SAM; link salary / VMI / Sodra bank lines';

    public function handle(): int
    {
        $from = Carbon::createFromFormat('Y-m', (string) $this->option('from'))->startOfMonth();
        $to = Carbon::createFromFormat('Y-m', (string) $this->option('to'))->endOfMonth();
        $actor = User::query()->orderBy('id')->first();

        if (! $this->option('skip-payslips')) {
            $this->info('Generating payslips '.$from->format('Y-m').' … '.$to->format('Y-m'));
            $result = HistoricalEmployeePayslipGenerator::run(
                actor: $actor,
                dryRun: false,
                email: false,
                force: false,
                fromMonth: $from->format('Y-m'),
                toMonth: $to->format('Y-m'),
            );
            $this->info('Payslips: created '.$result['created'].', PDFs '.$result['payslips'].', bank links '.$result['linked'].', skipped '.$result['skipped']);
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
            foreach ($result['unmatched'] as $label) {
                $this->warn('Unmatched DU: '.$label);
            }
        }

        $cursor = $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $year = (int) $cursor->year;
            $month = (int) $cursor->month;
            $label = $cursor->format('Y-m');

            $payments = EmployeeMonthlyPayment::query()
                ->with('employee')
                ->whereDate('payment_date', '>=', $cursor->copy()->startOfMonth()->toDateString())
                ->whereDate('payment_date', '<=', $cursor->copy()->endOfMonth()->toDateString())
                ->orderBy('payment_date')
                ->get();

            if ($payments->isEmpty()) {
                $this->warn($label.': no employee payments — skip journal/GPM313/SAM');
                $cursor->addMonth();

                continue;
            }

            $journal = null;
            if (! $this->option('skip-journal')) {
                try {
                    $journal = PayrollAccrualJournalDocumentGenerator::createForMonth($year, $month, $payments, $actor);
                    $this->info($label.': journal document #'.$journal->getKey().' → SharePoint');
                } catch (Throwable $exception) {
                    $this->error($label.': journal failed — '.$exception->getMessage());
                }
            }

            $gpm = null;
            if (! $this->option('skip-gpm313')) {
                try {
                    $gpm = Gpm313ReportDocumentGenerator::create($year, $month, $actor);
                    $this->info($label.': GPM313 document #'.$gpm->getKey().' → SharePoint');
                } catch (Throwable $exception) {
                    $this->error($label.': GPM313 failed — '.$exception->getMessage());
                }
            } else {
                $gpm = $this->findTypedDocument(Gpm313ReportDocumentGenerator::DOCUMENT_TYPE_NAME, $year, $month);
            }

            $sam = null;
            if (! $this->option('skip-sam')) {
                try {
                    $sam = SamReportDocumentGenerator::create($year, $month, $actor);
                    $this->info($label.': SAM document #'.$sam->getKey().' → SharePoint');
                } catch (Throwable $exception) {
                    $this->error($label.': SAM failed — '.$exception->getMessage());
                }
            } else {
                $sam = $this->findTypedDocument(SamReportDocumentGenerator::DOCUMENT_TYPE_NAME, $year, $month);
            }

            if (! $this->option('skip-bank-link') && $journal instanceof Document) {
                $linked = $this->linkBulkSalaryBanksToJournal($journal, $year, $month);
                if ($linked > 0) {
                    $this->info($label.': linked '.$linked.' Atlyginimas bank line(s) to journal');
                }
            }

            if (! $this->option('skip-bank-link')) {
                $gpm ??= $this->findTypedDocument(Gpm313ReportDocumentGenerator::DOCUMENT_TYPE_NAME, $year, $month);
                if ($gpm instanceof Document) {
                    $linkedGpm = $this->linkVmiGpmBanksToDeclaration($gpm, $year, $month);
                    if ($linkedGpm > 0) {
                        $this->info($label.': linked '.$linkedGpm.' VMI GPM bank line(s) to GPM313');
                    }
                }

                $sam ??= $this->findTypedDocument(SamReportDocumentGenerator::DOCUMENT_TYPE_NAME, $year, $month);
                if ($sam instanceof Document) {
                    $linkedSam = $this->linkSodraBanksToSam($sam, $year, $month);
                    if ($linkedSam > 0) {
                        $this->info($label.': linked '.$linkedSam.' Sodra bank line(s) to SAM');
                    }
                }
            }

            $cursor->addMonth();
        }

        return self::SUCCESS;
    }

    private function findTypedDocument(string $typeName, int $year, int $month): ?Document
    {
        $typeId = \App\Models\DocumentType::query()
            ->where('name', $typeName)
            ->value('id');

        if ($typeId === null) {
            return null;
        }

        return Document::query()
            ->where('document_type_id', $typeId)
            ->whereDate('document_date', Carbon::create($year, $month, 1)->endOfMonth()->toDateString())
            ->orderByDesc('id')
            ->first();
    }

    private function linkBulkSalaryBanksToJournal(Document $journal, int $year, int $month): int
    {
        // Bank pays prior month's salary early next month: "Atlyginimas už 07 mėn" in August → July journal.
        $accrualMonth = $month;
        $linked = 0;
        $period = Carbon::create($year, $month, 1);
        $expectedNet = round((float) EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $period->copy()->startOfMonth()->toDateString())
            ->whereDate('payment_date', '<=', $period->copy()->endOfMonth()->toDateString())
            ->sum('net_amount'), 2);

        $candidates = BankStatementTransaction::query()
            ->where('direction', 'debit')
            ->whereNull('invoice_id')
            ->whereNull('payment_id')
            ->whereNull('employee_monthly_payment_id')
            ->whereNull('document_id')
            ->where(function ($q) use ($accrualMonth): void {
                $mm = str_pad((string) $accrualMonth, 2, '0', STR_PAD_LEFT);
                $q->where('description', 'like', '%Atlyginimas%')
                    ->orWhere('counterpart_name', 'like', '%Atlyginimas%')
                    ->orWhere('description', 'like', '%salary%')
                    ->orWhere('counterpart_name', 'like', '%salary%');
            })
            ->orderBy('booking_date')
            ->get();

        foreach ($candidates as $transaction) {
            $byMonth = $this->mentionsAccrualMonth($transaction, $accrualMonth);
            $byAmount = abs(round((float) $transaction->amount, 2) - $expectedNet) < 0.02;

            // SEB text sometimes says the payment month ("už 08 mėn") while the DU journal is the prior accrual month.
            if (! $byMonth && ! $byAmount) {
                continue;
            }

            if ($byAmount && ! $byMonth) {
                $booking = $transaction->booking_date;
                if ($booking === null) {
                    continue;
                }
                $accrualEnd = Carbon::create($year, $month, 1)->endOfMonth();
                if ($booking->lt($accrualEnd->copy()->startOfMonth())
                    || $booking->gt($accrualEnd->copy()->addDays(45))) {
                    continue;
                }
            }

            if ((int) $transaction->document_id === (int) $journal->getKey()) {
                continue;
            }

            try {
                BankStatementDocumentMatcher::link($transaction, $journal);
                $linked++;
            } catch (Throwable $exception) {
                $this->warn('Bank #'.$transaction->getKey().': '.$exception->getMessage());
            }
        }

        return $linked;
    }

    private function mentionsAccrualMonth(BankStatementTransaction $transaction, int $month): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
        ]))));

        if ($haystack === '' || ! str_contains($haystack, 'atlyginimas')) {
            return false;
        }

        $mm = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        // "už 05 mėn", "uz 5 men", "07 mèn"
        return (bool) preg_match('/\b0?'.$month.'\b|\b'.$mm.'\b/u', $haystack);
    }

    private function linkVmiGpmBanksToDeclaration(Document $gpm313, int $year, int $month): int
    {
        $period = Carbon::create($year, $month, 1);
        $monthPayments = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $period->copy()->startOfMonth()->toDateString())
            ->whereDate('payment_date', '<=', $period->copy()->endOfMonth()->toDateString())
            ->get(['gpm_amount', 'comment', 'payment_date']);

        $endDay = (int) $period->copy()->endOfMonth()->day;
        $journalExpected = round((float) $monthPayments
            ->filter(function ($payment) use ($endDay): bool {
                $comment = mb_strtolower((string) ($payment->comment ?? ''));
                $day = (int) ($payment->payment_date?->day ?? 0);

                return str_contains($comment, 'imported from du journal') || $day === $endDay;
            })
            ->sum('gpm_amount'), 2);
        $allExpected = round((float) $monthPayments->sum('gpm_amount'), 2);
        $expected = $journalExpected > 0.005 ? $journalExpected : $allExpected;

        return $this->linkAuthorityBanksByAmount(
            $gpm313,
            $year,
            $month,
            $expected,
            fn (BankStatementTransaction $transaction): bool => BankStatementDocumentMatcher::isVmiGpmTransfer($transaction)
                || $this->looksLikeVmiGpmRemittance($transaction),
            fn (BankStatementTransaction $transaction): ?Document => BankStatementDocumentMatcher::suggestionGpm313For($transaction),
        );
    }

    private function linkSodraBanksToSam(Document $sam, int $year, int $month): int
    {
        $period = Carbon::create($year, $month, 1);
        $endDay = (int) $period->copy()->endOfMonth()->day;
        $monthPayments = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $period->copy()->startOfMonth()->toDateString())
            ->whereDate('payment_date', '<=', $period->copy()->endOfMonth()->toDateString())
            ->get(['sodra_employee_amount', 'sodra_employer_amount', 'comment', 'payment_date']);

        $journalExpected = round((float) $monthPayments
            ->filter(function ($payment) use ($endDay): bool {
                $comment = mb_strtolower((string) ($payment->comment ?? ''));
                $day = (int) ($payment->payment_date?->day ?? 0);

                return str_contains($comment, 'imported from du journal') || $day === $endDay;
            })
            ->sum(fn ($payment): float => (float) ($payment->sodra_employee_amount ?? 0) + (float) ($payment->sodra_employer_amount ?? 0)), 2);
        $allExpected = round((float) $monthPayments
            ->sum(fn ($payment): float => (float) ($payment->sodra_employee_amount ?? 0) + (float) ($payment->sodra_employer_amount ?? 0)), 2);
        $expected = $journalExpected > 0.005 ? $journalExpected : $allExpected;

        return $this->linkAuthorityBanksByAmount(
            $sam,
            $year,
            $month,
            $expected,
            fn (BankStatementTransaction $transaction): bool => BankStatementDocumentMatcher::isSodraSamTransfer($transaction)
                || $this->looksLikeSodraRemittance($transaction),
            fn (BankStatementTransaction $transaction): ?Document => BankStatementDocumentMatcher::suggestionSamFor($transaction),
        );
    }

    /**
     * @param  callable(BankStatementTransaction): bool  $isMatch
     * @param  callable(BankStatementTransaction): (?Document)  $suggest
     */
    private function linkAuthorityBanksByAmount(
        Document $document,
        int $year,
        int $month,
        float $expected,
        callable $isMatch,
        callable $suggest,
    ): int {
        if ($expected < 0.005) {
            return 0;
        }

        $accrualEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $windowStart = $accrualEnd->copy()->startOfMonth();
        $windowEnd = $accrualEnd->copy()->addDays(45);

        $candidates = BankStatementTransaction::query()
            ->where('direction', 'debit')
            ->whereNull('invoice_id')
            ->whereNull('payment_id')
            ->whereNull('employee_monthly_payment_id')
            ->whereNull('document_id')
            ->whereDate('booking_date', '>=', $windowStart->toDateString())
            ->whereDate('booking_date', '<=', $windowEnd->toDateString())
            ->orderBy('booking_date')
            ->get();

        $linked = 0;
        $usedIds = [];

        foreach ($candidates as $transaction) {
            $amount = round(abs((float) $transaction->amount), 2);
            if (abs($amount - $expected) >= 0.015) {
                continue;
            }

            if (! $isMatch($transaction)) {
                continue;
            }

            $suggested = $suggest($transaction);
            if ($suggested !== null && (int) $suggested->getKey() !== (int) $document->getKey()) {
                continue;
            }

            try {
                BankStatementDocumentMatcher::link($transaction, $document);
                $linked++;
                $usedIds[(int) $transaction->getKey()] = true;
            } catch (Throwable $exception) {
                $this->warn('Bank #'.$transaction->getKey().': '.$exception->getMessage());
            }
        }

        // Split remittances (e.g. May GPM paid as 707.08 + 1583.53).
        if ($linked === 0) {
            $matchable = $candidates
                ->filter(fn (BankStatementTransaction $transaction): bool => $isMatch($transaction))
                ->values();

            $count = $matchable->count();
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    /** @var BankStatementTransaction $a */
                    $a = $matchable[$i];
                    /** @var BankStatementTransaction $b */
                    $b = $matchable[$j];
                    $sum = round(abs((float) $a->amount) + abs((float) $b->amount), 2);
                    if (abs($sum - $expected) >= 0.015) {
                        continue;
                    }

                    foreach ([$a, $b] as $transaction) {
                        if (isset($usedIds[(int) $transaction->getKey()])) {
                            continue 2;
                        }
                        $suggested = $suggest($transaction);
                        if ($suggested !== null && (int) $suggested->getKey() !== (int) $document->getKey()) {
                            continue 2;
                        }
                    }

                    foreach ([$a, $b] as $transaction) {
                        try {
                            BankStatementDocumentMatcher::link($transaction, $document);
                            $linked++;
                            $usedIds[(int) $transaction->getKey()] = true;
                        } catch (Throwable $exception) {
                            $this->warn('Bank #'.$transaction->getKey().': '.$exception->getMessage());
                        }
                    }

                    if ($linked > 0) {
                        break 2;
                    }
                }
            }
        }

        return $linked;
    }

    private function looksLikeVmiGpmRemittance(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
        ]))));

        if (preg_match('/\bPVM\b|\b1001\b/u', $haystack) === 1) {
            return false;
        }

        return str_contains($haystack, '1311')
            || str_contains($haystack, 'GPM')
            || str_contains($haystack, 'VMI')
            || str_contains($haystack, 'VALSTYBIN')
            || str_contains($haystack, 'MOKES');
    }

    private function looksLikeSodraRemittance(BankStatementTransaction $transaction): bool
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
            $transaction->counterpart_iban,
        ]))));

        return str_contains($haystack, '252')
            || str_contains($haystack, 'SODRA')
            || str_contains($haystack, 'VSDFV')
            || str_contains($haystack, 'SADM');
    }
}
