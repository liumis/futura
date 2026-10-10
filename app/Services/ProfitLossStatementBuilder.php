<?php

namespace App\Services;

use App\Models\InvoiceCode;
use Illuminate\Support\Collection;

/**
 * Lithuanian profit (loss) statement — pelno (nuostolių) ataskaita.
 *
 * Income (class 5) = credit − debit. Expenses (class 6) = debit − credit.
 * Lines follow the standard Lithuanian layout by chart-of-accounts groups.
 */
final class ProfitLossStatementBuilder
{
    /**
     * @param  iterable<int, InvoiceCode>  $codes
     * @return array{
     *     lines: list<array{key: string, style: string, label: string, label_lt: string, amount: float}>,
     *     revenue: float,
     *     expenses: float,
     *     gross: float,
     *     operating: float,
     *     profit_before_tax: float,
     *     net: float,
     *     is_profit: bool
     * }
     */
    public static function build(iterable $codes): array
    {
        $codes = Collection::make($codes);

        $sales = self::sumIncome($codes, ['50']);
        $otherOperatingIncome = self::sumIncome($codes, ['51', '54']);
        $financialIncome = self::sumIncome($codes, ['55', '56', '58']);
        $otherIncome = round(self::sumIncome($codes, ['5']) - $sales - $otherOperatingIncome - $financialIncome, 2);

        $costOfSales = self::sumExpense($codes, ['60']);
        $selling = self::sumExpense($codes, ['62']);
        $admin = self::sumExpense($codes, ['63']);
        $otherOperatingExpense = self::sumExpense($codes, ['61', '64']);
        $financialExpense = self::sumExpense($codes, ['67', '68']);
        $incomeTax = self::sumExpense($codes, ['69']);
        $otherExpense = round(
            self::sumExpense($codes, ['6'])
            - $costOfSales
            - $selling
            - $admin
            - $otherOperatingExpense
            - $financialExpense
            - $incomeTax,
            2,
        );

        $gross = round($sales - $costOfSales, 2);
        $operating = round(
            $gross
            - $selling
            - $admin
            + $otherOperatingIncome
            - $otherOperatingExpense
            + $otherIncome
            - $otherExpense,
            2,
        );
        $profitBeforeTax = round($operating + $financialIncome - $financialExpense, 2);
        $net = round($profitBeforeTax - $incomeTax, 2);

        $lines = [
            self::line('sales', 'item', 'Sales revenue', 'Pardavimo pajamos', $sales),
            self::line('cost_of_sales', 'item', 'Cost of sales', 'Pardavimo savikaina', $costOfSales),
            self::line('gross', 'subtotal', 'Gross profit (loss)', 'Bendrasis pelnas (nuostoliai)', $gross),
            self::line('selling', 'item', 'Selling expenses', 'Pardavimo sąnaudos', $selling),
            self::line('admin', 'item', 'General and administrative expenses', 'Bendrosios ir administracinės sąnaudos', $admin),
        ];

        if (abs($otherOperatingIncome) >= 0.005) {
            $lines[] = self::line('other_operating_income', 'item', 'Other operating income', 'Kitos veiklos pajamos', $otherOperatingIncome);
        }
        if (abs($otherOperatingExpense) >= 0.005) {
            $lines[] = self::line('other_operating_expense', 'item', 'Other operating expenses', 'Kitos veiklos sąnaudos', $otherOperatingExpense);
        }
        if (abs($otherIncome) >= 0.005) {
            $lines[] = self::line('other_income', 'item', 'Other income', 'Kitos pajamos', $otherIncome);
        }
        if (abs($otherExpense) >= 0.005) {
            $lines[] = self::line('other_expense', 'item', 'Other expenses', 'Kitos sąnaudos', $otherExpense);
        }

        $lines[] = self::line('operating', 'subtotal', 'Operating profit (loss)', 'Veiklos pelnas (nuostoliai)', $operating);
        $lines[] = self::line('financial_income', 'item', 'Financial and investment income', 'Finansinės ir investicinės veiklos pajamos', $financialIncome);
        $lines[] = self::line('financial_expense', 'item', 'Financial and investment expenses', 'Finansinės ir investicinės veiklos sąnaudos', $financialExpense);
        $lines[] = self::line('profit_before_tax', 'subtotal', 'Profit (loss) before tax', 'Pelnas (nuostoliai) prieš apmokestinimą', $profitBeforeTax);
        $lines[] = self::line('income_tax', 'item', 'Income tax', 'Pelno mokestis', $incomeTax);
        $lines[] = self::line('net', 'total', 'Net profit (loss)', 'Grynasis pelnas (nuostoliai)', $net);

        $revenue = round($sales + $otherOperatingIncome + $financialIncome + $otherIncome, 2);
        $expenses = round(
            $costOfSales + $selling + $admin + $otherOperatingExpense + $financialExpense + $incomeTax + $otherExpense,
            2,
        );

        return [
            'lines' => $lines,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'gross' => $gross,
            'operating' => $operating,
            'profit_before_tax' => $profitBeforeTax,
            'net' => $net,
            'is_profit' => $net >= -0.005,
        ];
    }

    public static function netIncome(float $debit, float $credit): float
    {
        return round($credit - $debit, 2);
    }

    public static function netExpense(float $debit, float $credit): float
    {
        return round($debit - $credit, 2);
    }

    public static function amountForCode(string $code, float $debit, float $credit): float
    {
        if (str_starts_with($code, '5')) {
            return self::netIncome($debit, $credit);
        }

        if (str_starts_with($code, '6')) {
            return self::netExpense($debit, $credit);
        }

        return 0.0;
    }

    /**
     * @param  Collection<int, InvoiceCode>  $codes
     * @param  list<string>  $prefixes
     */
    private static function sumIncome(Collection $codes, array $prefixes): float
    {
        return round((float) $codes
            ->filter(fn (InvoiceCode $code): bool => self::startsWithAny((string) $code->code, $prefixes))
            ->sum(fn (InvoiceCode $code): float => self::netIncome(
                (float) ($code->turnover_debit ?? $code->debit ?? 0),
                (float) ($code->turnover_credit ?? $code->credit ?? 0),
            )), 2);
    }

    /**
     * @param  Collection<int, InvoiceCode>  $codes
     * @param  list<string>  $prefixes
     */
    private static function sumExpense(Collection $codes, array $prefixes): float
    {
        return round((float) $codes
            ->filter(fn (InvoiceCode $code): bool => self::startsWithAny((string) $code->code, $prefixes))
            ->sum(fn (InvoiceCode $code): float => self::netExpense(
                (float) ($code->turnover_debit ?? $code->debit ?? 0),
                (float) ($code->turnover_credit ?? $code->credit ?? 0),
            )), 2);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key: string, style: string, label: string, label_lt: string, amount: float}
     */
    private static function line(string $key, string $style, string $label, string $labelLt, float $amount): array
    {
        return [
            'key' => $key,
            'style' => $style,
            'label' => $label,
            'label_lt' => $labelLt,
            'amount' => round($amount, 2),
        ];
    }
}
