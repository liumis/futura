<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BankStatementSalaryMatcher
{
    /**
     * @return Collection<int, array{transaction: BankStatementTransaction, employee: Employee}>
     */
    public static function matches(?Collection $transactions = null): Collection
    {
        $employees = Employee::query()->get();
        if ($employees->isEmpty()) {
            return collect();
        }

        $ibanMap = [];
        foreach ($employees as $employee) {
            $iban = CompanyBankAccount::normalizeIban($employee->bank_account);
            if (filled($iban)) {
                $ibanMap[$iban] = $employee;
            }
        }

        $rows = $transactions ?? BankStatementTransaction::query()
            ->where('direction', BankStatementDirection::Debit)
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get();

        $matched = collect();

        foreach ($rows as $transaction) {
            if (! $transaction instanceof BankStatementTransaction) {
                continue;
            }

            if ($transaction->direction !== BankStatementDirection::Debit) {
                continue;
            }

            if ($transaction->isInternalTransfer() || $transaction->isBankServiceFee()) {
                continue;
            }

            $employee = self::matchEmployee($transaction, $employees, $ibanMap);
            if ($employee === null) {
                continue;
            }

            $matched->push([
                'transaction' => $transaction,
                'employee' => $employee,
            ]);
        }

        return $matched;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, Employee>  $ibanMap
     */
    public static function matchEmployee(
        BankStatementTransaction $transaction,
        Collection $employees,
        array $ibanMap = [],
    ): ?Employee {
        $name = self::salaryPayeeName($transaction);
        if ($name === null) {
            return null;
        }

        $iban = CompanyBankAccount::normalizeIban($transaction->counterpart_iban);
        if (filled($iban) && isset($ibanMap[$iban])) {
            return $ibanMap[$iban];
        }

        $normalized = self::normalizePersonName($name);

        foreach ($employees as $employee) {
            if (self::normalizePersonName($employee->fullName()) === $normalized) {
                return $employee;
            }
        }

        return null;
    }

    public static function isSalaryTransfer(BankStatementTransaction $transaction): bool
    {
        return self::salaryPayeeName($transaction) !== null;
    }

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return Collection<int, EmployeeMonthlyPayment> keyed by transaction id
     */
    public static function suggestFor(Collection $transactions): Collection
    {
        $suggestions = collect();

        foreach ($transactions as $transaction) {
            if (! $transaction instanceof BankStatementTransaction || $transaction->isResolved()) {
                continue;
            }

            $payment = self::suggestionFor($transaction);
            if ($payment !== null) {
                $suggestions->put($transaction->getKey(), $payment);
            }
        }

        return $suggestions;
    }

    public static function suggestionFor(BankStatementTransaction $transaction): ?EmployeeMonthlyPayment
    {
        if ($transaction->isResolved() || filled($transaction->employee_monthly_payment_id)) {
            return null;
        }

        $employee = self::matchEmployee(
            $transaction,
            Employee::query()->get(),
            self::ibanMap(),
        );

        if ($employee === null) {
            return null;
        }

        $date = optional($transaction->booking_date)?->toDateString()
            ?? optional($transaction->value_date)?->toDateString();
        $net = round((float) $transaction->amount, 2);

        if ($date === null || $net <= 0) {
            return null;
        }

        $exact = EmployeeMonthlyPayment::query()
            ->with(['employee', 'payslipDocument'])
            ->where('employee_id', $employee->getKey())
            ->whereDate('payment_date', $date)
            ->first();

        if ($exact !== null) {
            $existingNet = $exact->net_amount !== null ? round((float) $exact->net_amount, 2) : null;
            if ($existingNet === null || abs($existingNet - $net) <= 0.05) {
                return $exact;
            }

            return null;
        }

        $from = Carbon::parse($date)->subDays(7)->toDateString();
        $to = Carbon::parse($date)->addDays(7)->toDateString();

        return EmployeeMonthlyPayment::query()
            ->with(['employee', 'payslipDocument'])
            ->where('employee_id', $employee->getKey())
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->get()
            ->first(function (EmployeeMonthlyPayment $payment) use ($net): bool {
                $existingNet = $payment->net_amount !== null ? round((float) $payment->net_amount, 2) : null;

                return $existingNet !== null && abs($existingNet - $net) <= 0.05;
            });
    }

    public static function link(BankStatementTransaction $transaction, EmployeeMonthlyPayment $payment): void
    {
        if ((int) $transaction->employee_monthly_payment_id === (int) $payment->getKey()) {
            return;
        }

        if ($transaction->employee_monthly_payment_id !== null) {
            throw new \RuntimeException('This bank payment is already linked to another payslip.');
        }

        if ($transaction->invoice_id || $transaction->invoices()->exists()) {
            BankStatementInvoiceMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->payment_id) {
            BankStatementPaymentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->document_id) {
            BankStatementDocumentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($transaction->business_trip_request_id) {
            BankStatementBusinessTripMatcher::unlink($transaction);
            $transaction->refresh();
        }

        $transaction->forceFill([
            'employee_monthly_payment_id' => $payment->getKey(),
            'invoice_id' => null,
            'payment_id' => null,
            'document_id' => null,
            'business_trip_request_id' => null,
        ])->save();
    }

    public static function unlink(BankStatementTransaction $transaction): void
    {
        if (! $transaction->employee_monthly_payment_id) {
            return;
        }

        $transaction->update([
            'employee_monthly_payment_id' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function pickerOptionsFor(BankStatementTransaction $transaction): array
    {
        $suggested = self::suggestionFor($transaction);
        $query = EmployeeMonthlyPayment::query()
            ->with('employee')
            ->orderByDesc('payment_date')
            ->limit(40);

        if ($suggested !== null) {
            $query->whereKeyNot($suggested->getKey());
        }

        $options = [];
        if ($suggested !== null) {
            $options[$suggested->getKey()] = self::pickerLabel($suggested);
        }

        foreach ($query->get() as $payment) {
            $options[$payment->getKey()] = self::pickerLabel($payment);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function searchPayments(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return EmployeeMonthlyPayment::query()
            ->with('employee')
            ->where(function ($query) use ($search): void {
                $query
                    ->where('comment', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', function ($employee) use ($search): void {
                        $employee
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('surname', 'like', '%'.$search.'%');
                    });
            })
            ->orderByDesc('payment_date')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (EmployeeMonthlyPayment $payment): array => [
                $payment->getKey() => self::pickerLabel($payment),
            ])
            ->all();
    }

    public static function pickerLabel(EmployeeMonthlyPayment $payment): string
    {
        $payment->loadMissing('employee');

        return implode(' · ', array_filter([
            $payment->employee?->fullName(),
            optional($payment->payment_date)->format('Y-m-d'),
            $payment->net_amount !== null ? number_format((float) $payment->net_amount, 2, '.', '').' €' : null,
        ], fn (mixed $value): bool => filled($value)));
    }

    /**
     * @return array<string, Employee>
     */
    protected static function ibanMap(): array
    {
        $ibanMap = [];

        foreach (Employee::query()->get() as $employee) {
            $iban = CompanyBankAccount::normalizeIban($employee->bank_account);
            if (filled($iban)) {
                $ibanMap[$iban] = $employee;
            }
        }

        return $ibanMap;
    }

    public static function salaryPayeeName(BankStatementTransaction $transaction): ?string
    {
        $haystack = trim((string) ($transaction->counterpart_name ?: ''));
        if ($haystack === '') {
            $haystack = trim((string) ($transaction->description ?: ''));
        }

        if ($haystack === '') {
            return null;
        }

        if (preg_match('/^(?:DU|D\.?\s*U\.?)\s+(.+)$/iu', $haystack, $matches) === 1) {
            $name = trim((string) $matches[1]);

            return $name !== '' ? $name : null;
        }

        return null;
    }

    public static function normalizePersonName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return Str::lower($collapsed);
    }
}
