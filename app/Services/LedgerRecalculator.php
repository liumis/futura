<?php

namespace App\Services;

use App\Models\EmployeeMonthlyPayment;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LedgerRecalculator
{
    public const DEFAULT_FROM = '2026-04-01';

    /**
     * Wipe generated payroll GL carriers from $from, regenerate invoice finance lines
     * and payroll accruals. Opening balances stay zero (no import).
     *
     * @return array{
     *     from: string,
     *     expense_types_mapped: int,
     *     invoices: int,
     *     invoice_lines: int,
     *     payroll_months: int,
     *     payroll_invoices: int,
     *     payroll_lines: int,
     *     coverage: array{
     *         invoices_total: int,
     *         invoices_with_lines: int,
     *         invoices_missing_lines: int,
     *         invoices_unbalanced: int,
     *         payroll_months_total: int,
     *         payroll_months_posted: int
     *     }
     * }
     */
    public static function recalculate(?string $fromDate = null): array
    {
        $from = Carbon::parse($fromDate ?: self::DEFAULT_FROM)->toDateString();

        return DB::transaction(function () use ($from): array {
            $mapped = ExpenseTypeAccountMapper::syncDefaults();
            $invoiceResult = InvoiceDefaultFinanceLines::recalculateFrom($from);
            $payrollResult = PayrollLedgerPoster::recalculateFrom($from);

            return [
                'from' => $from,
                'expense_types_mapped' => $mapped,
                'invoices' => $invoiceResult['invoices'],
                'invoice_lines' => $invoiceResult['lines'],
                'payroll_months' => $payrollResult['months'],
                'payroll_invoices' => $payrollResult['invoices'],
                'payroll_lines' => $payrollResult['lines'],
                'coverage' => self::coverage($from),
            ];
        });
    }

    /**
     * @return array{
     *     invoices_total: int,
     *     invoices_with_lines: int,
     *     invoices_missing_lines: int,
     *     invoices_unbalanced: int,
     *     payroll_months_total: int,
     *     payroll_months_posted: int
     * }
     */
    public static function coverage(?string $fromDate = null): array
    {
        $from = Carbon::parse($fromDate ?: self::DEFAULT_FROM)->toDateString();

        $base = Invoice::query()
            ->whereNull('gl_generated_type')
            ->where(fn ($q) => $q->where('is_own_account', false)->orWhereNull('is_own_account'))
            ->whereDate('invoice_date', '>=', $from);

        $total = (clone $base)->count();
        $withLines = (clone $base)->whereHas('financeLines')->count();

        $unbalanced = 0;
        (clone $base)
            ->whereHas('financeLines')
            ->with('financeLines')
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$unbalanced): void {
                foreach ($chunk as $invoice) {
                    $debit = round((float) $invoice->financeLines->sum('debit'), 2);
                    $credit = round((float) $invoice->financeLines->sum('credit'), 2);
                    if (abs($debit - $credit) >= 0.005) {
                        $unbalanced++;
                    }
                }
            });

        $payrollMonths = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $from)
            ->get(['payment_date'])
            ->map(fn ($p) => Carbon::parse($p->payment_date)->format('Y-m'))
            ->unique()
            ->values();

        $posted = Invoice::query()
            ->where('gl_generated_type', PayrollLedgerPoster::SOURCE_TYPE)
            ->whereDate('invoice_date', '>=', $from)
            ->pluck('gl_generated_key')
            ->unique()
            ->count();

        return [
            'invoices_total' => $total,
            'invoices_with_lines' => $withLines,
            'invoices_missing_lines' => max(0, $total - $withLines),
            'invoices_unbalanced' => $unbalanced,
            'payroll_months_total' => $payrollMonths->count(),
            'payroll_months_posted' => $posted,
        ];
    }
}
