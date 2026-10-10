<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeMonthlyPayment;
use App\Models\ExpenseType;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts monthly payroll (DU) accruals into Didžioji knyga via GL carrier invoices.
 *
 * Dr 6304 wage expense (gross + employer Sodra)
 * Cr 4480 net pay
 * Cr 4481 GPM
 * Cr 4482 Sodra (employee + employer)
 */
class PayrollLedgerPoster
{
    public const SOURCE_TYPE = 'payroll';

    public const WAGE_EXPENSE = '6304';

    public const NET_PAYABLE = '4480';

    public const GPM_PAYABLE = '4481';

    public const SODRA_PAYABLE = '4482';

    /**
     * @return array{months: int, invoices: int, lines: int}
     */
    public static function recalculateFrom(string $fromDate): array
    {
        $from = Carbon::parse($fromDate)->startOfMonth();

        Invoice::withTrashed()
            ->where('gl_generated_type', self::SOURCE_TYPE)
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->each(function (Invoice $invoice): void {
                $invoice->forceDelete();
            });

        $months = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->orderBy('payment_date')
            ->pluck('payment_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->unique()
            ->values();

        $invoices = 0;
        $lines = 0;

        foreach ($months as $ym) {
            [$year, $month] = array_map('intval', explode('-', (string) $ym));
            $result = self::postMonth($year, $month);
            if ($result !== null) {
                $invoices++;
                $lines += $result['lines'];
            }
        }

        return [
            'months' => $months->count(),
            'invoices' => $invoices,
            'lines' => $lines,
        ];
    }

    /**
     * @return array{invoice_id: int, lines: int}|null
     */
    public static function postMonth(int $year, int $month): ?array
    {
        $key = sprintf('%04d-%02d', $year, $month);

        $payments = EmployeeMonthlyPayment::query()
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month)
            ->get();

        if ($payments->isEmpty()) {
            return null;
        }

        $gross = round((float) $payments->sum('gross_amount'), 2);
        $net = round((float) $payments->sum('net_amount'), 2);
        $gpm = round((float) $payments->sum('gpm_amount'), 2);
        $sodraEe = round((float) $payments->sum('sodra_employee_amount'), 2);
        $sodraEr = round((float) $payments->sum('sodra_employer_amount'), 2);
        $sodra = round($sodraEe + $sodraEr, 2);
        $expense = round($gross + $sodraEr, 2);

        if ($expense < 0.005) {
            return null;
        }

        $codes = self::codes();
        $contact = CompanySetting::instance()->syncContact();
        $expenseTypeId = ExpenseType::query()->where('name', 'Atlyginimai')->value('id');

        $entryDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $number = 'GL-DU-'.$key;

        return DB::transaction(function () use (
            $key,
            $contact,
            $expenseTypeId,
            $entryDate,
            $number,
            $codes,
            $expense,
            $net,
            $gpm,
            $sodra,
            $gross,
            $sodraEr,
            $payments,
        ): array {
            Invoice::withTrashed()
                ->where('gl_generated_type', self::SOURCE_TYPE)
                ->where('gl_generated_key', $key)
                ->each(fn (Invoice $invoice) => $invoice->forceDelete());

            $actorId = auth()->id()
                ?? User::query()->orderBy('id')->value('id');

            if ($actorId === null) {
                throw new RuntimeException('Cannot post payroll GL without a user (uploaded_by).');
            }

            $invoice = Invoice::query()->create([
                'contact_id' => $contact->id,
                'invoice_number' => $number,
                'invoice_date' => $entryDate,
                'sum_without_vat' => $expense,
                'vat' => 0,
                'sum_inc_vat' => $expense,
                'sum_inc_vat_eur' => $expense,
                'currency' => 'EUR',
                'currency_rate' => 1,
                'payment_term' => 0,
                'paid_amount' => 0,
                'paid' => false,
                'private_provider' => false,
                'is_own_account' => false,
                'income_type_id' => null,
                'expense_type_id' => $expenseTypeId,
                'gl_generated_type' => self::SOURCE_TYPE,
                'gl_generated_key' => $key,
                'comment' => sprintf(
                    'Payroll accrual %s · %d employee(s) · gross %s · employer Sodra %s',
                    $key,
                    $payments->count(),
                    number_format($gross, 2, '.', ''),
                    number_format($sodraEr, 2, '.', ''),
                ),
                'upload_date' => now()->toDateString(),
                'uploaded_by' => $actorId,
                'pdf_path' => '',
            ]);

            // Absorb 1-cent source rounding into Sodra payable so the entry balances.
            $sodraAdjusted = round($expense - $net - $gpm, 2);
            if ($sodraAdjusted < 0) {
                throw new RuntimeException(sprintf(
                    'Payroll %s invalid: expense %s < net+gpm %s',
                    $key,
                    number_format($expense, 2, '.', ''),
                    number_format($net + $gpm, 2, '.', ''),
                ));
            }

            $template = [
                [self::WAGE_EXPENSE, $expense, 0.0],
                [self::NET_PAYABLE, 0.0, $net],
                [self::GPM_PAYABLE, 0.0, $gpm],
                [self::SODRA_PAYABLE, 0.0, $sodraAdjusted],
            ];

            $debit = 0.0;
            $credit = 0.0;
            $sort = 0;

            foreach ($template as [$code, $d, $c]) {
                $d = round($d, 2);
                $c = round($c, 2);
                if (abs($d) < 0.005 && abs($c) < 0.005) {
                    continue;
                }

                $account = $codes[$code] ?? throw new RuntimeException('Missing payroll account '.$code);
                $invoice->financeLines()->create([
                    'name' => $account->name,
                    'invoice_code_id' => $account->id,
                    'debit' => $d,
                    'credit' => $c,
                    'sort_order' => $sort++,
                ]);
                $debit += $d;
                $credit += $c;
            }

            if (abs(round($debit - $credit, 2)) >= 0.005) {
                throw new RuntimeException(sprintf(
                    'Payroll %s unbalanced: debit %s credit %s (source sodra %s, posted %s)',
                    $key,
                    number_format($debit, 2, '.', ''),
                    number_format($credit, 2, '.', ''),
                    number_format($sodra, 2, '.', ''),
                    number_format($sodraAdjusted, 2, '.', ''),
                ));
            }

            return [
                'invoice_id' => (int) $invoice->id,
                'lines' => $sort,
            ];
        });
    }

    /**
     * @return array<string, InvoiceCode>
     */
    private static function codes(): array
    {
        $map = [
            self::WAGE_EXPENSE => 'Darbuotojų darbo užmokestis ir su juo susijusios sąnaudos',
            self::NET_PAYABLE => 'Mokėtinas darbo užmokestis',
            self::GPM_PAYABLE => 'Mokėtinas gyventojų pajamų mokestis',
            self::SODRA_PAYABLE => 'Mokėtinos socialinio draudimo įmokos',
        ];

        $codes = [];
        foreach ($map as $code => $name) {
            $codes[$code] = ExpenseTypeAccountMapper::ensureCode($code, $name);
        }

        return $codes;
    }
}
