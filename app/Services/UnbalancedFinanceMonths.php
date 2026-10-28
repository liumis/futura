<?php

namespace App\Services;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Models\InvoiceFinanceLine;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Months where debit ≠ credit (invoice journals or own-account bank transfers).
 */
final class UnbalancedFinanceMonths
{
    private const TOLERANCE = 0.005;

    /**
     * @return Collection<int, array{
     *     month_key: string,
     *     title: string,
     *     kind: 'journal'|'own_account',
     *     debit: float,
     *     credit: float,
     *     difference: float,
     *     difference_label: string,
     * }>
     */
    public static function all(): Collection
    {
        return self::journalMonths()
            ->concat(self::ownAccountTransferMonths())
            ->sortByDesc('month_key')
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     month_key: string,
     *     title: string,
     *     kind: 'journal',
     *     debit: float,
     *     credit: float,
     *     difference: float,
     *     difference_label: string,
     * }>
     */
    public static function journalMonths(): Collection
    {
        if (! Schema::hasTable('invoice_finance_lines') || ! Schema::hasTable('invoices')) {
            return collect();
        }

        $monthExpr = self::monthExpression('i.invoice_date');

        $rows = InvoiceFinanceLine::query()
            ->from('invoice_finance_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->whereNotNull('i.invoice_date')
            ->selectRaw("{$monthExpr} as month_key, SUM(l.debit) as debit, SUM(l.credit) as credit")
            ->groupBy(DB::raw($monthExpr))
            ->orderByDesc('month_key')
            ->get();

        return $rows
            ->map(function (object $row): ?array {
                $debit = round((float) $row->debit, 2);
                $credit = round((float) $row->credit, 2);
                $difference = round($debit - $credit, 2);

                if (abs($difference) < self::TOLERANCE) {
                    return null;
                }

                $monthKey = (string) $row->month_key;

                return [
                    'month_key' => $monthKey,
                    'title' => self::monthTitle($monthKey),
                    'kind' => 'journal',
                    'debit' => $debit,
                    'credit' => $credit,
                    'difference' => $difference,
                    'difference_label' => Money::format(abs($difference)),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Own-account bank moves should balance credit vs debit within each month.
     *
     * @return Collection<int, array{
     *     month_key: string,
     *     title: string,
     *     kind: 'own_account',
     *     debit: float,
     *     credit: float,
     *     difference: float,
     *     difference_label: string,
     * }>
     */
    public static function ownAccountTransferMonths(): Collection
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return collect();
        }

        $byMonth = BankStatementTransaction::query()
            ->whereNotNull('month_key')
            ->where('month_key', '!=', '')
            ->orderByDesc('month_key')
            ->get(['id', 'month_key', 'direction', 'amount', 'account_iban', 'counterpart_iban', 'counterpart_name', 'description', 'end_to_end_id', 'comment'])
            ->groupBy('month_key');

        $out = collect();

        foreach ($byMonth as $monthKey => $transactions) {
            $internal = $transactions->filter(
                fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer(),
            );

            if ($internal->isEmpty()) {
                continue;
            }

            $credit = round($internal
                ->filter(fn (BankStatementTransaction $tx): bool => $tx->direction === BankStatementDirection::Credit)
                ->sum(fn (BankStatementTransaction $tx): float => (float) $tx->amount), 2);
            $debit = round($internal
                ->filter(fn (BankStatementTransaction $tx): bool => $tx->direction === BankStatementDirection::Debit)
                ->sum(fn (BankStatementTransaction $tx): float => (float) $tx->amount), 2);
            $difference = round($debit - $credit, 2);

            if (abs($difference) < self::TOLERANCE) {
                continue;
            }

            $monthKey = (string) $monthKey;
            $out->push([
                'month_key' => $monthKey,
                'title' => self::monthTitle($monthKey),
                'kind' => 'own_account',
                'debit' => $debit,
                'credit' => $credit,
                'difference' => $difference,
                'difference_label' => Money::format(abs($difference)),
            ]);
        }

        return $out->values();
    }

    public static function monthTitle(string $monthKey): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $monthKey)->format('F Y');
        } catch (\Throwable) {
            return $monthKey;
        }
    }

    private static function monthExpression(string $column): string
    {
        $driver = Schema::getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
