<?php

namespace App\Services;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\BankStatementTransaction;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\BankStatements\BankStatementSalaryMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class HistoricalEmployeePayslipGenerator
{
    /**
     * @return array{
     *     created: int,
     *     payslips: int,
     *     linked: int,
     *     skipped: int,
     *     unmatched: list<string>,
     *     errors: list<string>
     * }
     */
    public static function run(
        ?User $actor = null,
        bool $dryRun = false,
        bool $email = false,
        bool $force = false,
        ?int $limit = null,
        ?string $fromMonth = null,
        ?string $toMonth = null,
    ): array {
        $created = 0;
        $payslips = 0;
        $linked = 0;
        $skipped = 0;
        $unmatched = [];
        $errors = [];
        $processed = 0;
        $handledPaymentIds = [];

        $allowLocal = ! SharepointSetting::instance()->isReady();
        $calculator = app(LithuanianPayrollCalculator::class);
        $matches = BankStatementSalaryMatcher::matches();
        [$from, $to] = self::normalizeMonthRange($fromMonth, $toMonth);

        foreach ($matches as $match) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            /** @var BankStatementTransaction $transaction */
            $transaction = $match['transaction'];
            /** @var Employee $employee */
            $employee = $match['employee'];

            if (! self::transactionInMonthRange($transaction, $from, $to)) {
                continue;
            }

            $processed++;

            try {
                $result = self::syncBankTransfer(
                    $transaction,
                    $employee,
                    $calculator,
                    $actor,
                    $dryRun,
                    $email,
                    $force,
                    $allowLocal,
                );
            } catch (Throwable $exception) {
                $errors[] = 'Bank #'.$transaction->getKey().': '.$exception->getMessage();

                continue;
            }

            $created += $result['created'] ? 1 : 0;
            $payslips += $result['payslip'] ? 1 : 0;
            $linked += $result['linked'] ? 1 : 0;
            $skipped += $result['skipped'] ? 1 : 0;
            if ($result['payment_id'] !== null) {
                $handledPaymentIds[] = $result['payment_id'];
            }
        }

        $unmatched = self::unmatchedSalaryLabels($matches, $from, $to);

        if ($limit === null || $processed < $limit) {
            $remaining = $limit !== null ? $limit - $processed : null;
            $existing = self::existingPaymentsMissingPayslip($remaining, $handledPaymentIds, $from, $to);

            foreach ($existing as $payment) {
                try {
                    if ($dryRun) {
                        $payslips++;

                        continue;
                    }

                    if ($payment->status !== EmployeeMonthlyPaymentStatus::Payed) {
                        $payment->markPayed();
                    }

                    EmployeePayslipDocumentGenerator::createForPayment(
                        $payment->fresh(['employee', 'payslipDocument']) ?? $payment,
                        $actor,
                        email: $email,
                        allowLocalStorage: $allowLocal,
                    );
                    $payslips++;
                } catch (Throwable $exception) {
                    $errors[] = 'Payment #'.$payment->getKey().': '.$exception->getMessage();
                }
            }
        }

        return [
            'created' => $created,
            'payslips' => $payslips,
            'linked' => $linked,
            'skipped' => $skipped,
            'unmatched' => $unmatched,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected static function normalizeMonthRange(?string $fromMonth, ?string $toMonth): array
    {
        $from = filled($fromMonth) ? Carbon::createFromFormat('Y-m', $fromMonth)->startOfMonth() : null;
        $to = filled($toMonth) ? Carbon::createFromFormat('Y-m', $toMonth)->endOfMonth() : null;

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfMonth(), $from->copy()->endOfMonth()];
        }

        return [$from, $to];
    }

    protected static function transactionInMonthRange(
        BankStatementTransaction $transaction,
        ?Carbon $from,
        ?Carbon $to,
    ): bool {
        if ($from === null && $to === null) {
            return true;
        }

        $date = $transaction->booking_date ?? $transaction->value_date;
        if ($date === null) {
            return false;
        }

        if ($from !== null && $date->lt($from)) {
            return false;
        }

        if ($to !== null && $date->gt($to)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, array{transaction: BankStatementTransaction, employee: Employee}>  $matches
     * @return list<string>
     */
    protected static function unmatchedSalaryLabels(Collection $matches, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $matchedIds = $matches
            ->map(fn (array $row): int => (int) $row['transaction']->getKey())
            ->all();

        return BankStatementTransaction::query()
            ->where('direction', 'debit')
            ->whereNotIn('id', $matchedIds)
            ->when($from !== null, fn ($q) => $q->whereDate('booking_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('booking_date', '<=', $to->toDateString()))
            ->orderBy('booking_date')
            ->get()
            ->filter(fn (BankStatementTransaction $row): bool => BankStatementSalaryMatcher::salaryPayeeName($row) !== null)
            ->map(function (BankStatementTransaction $row): string {
                $name = BankStatementSalaryMatcher::salaryPayeeName($row) ?: (string) $row->counterpart_name;

                return trim($name.' · '.optional($row->booking_date)?->toDateString().' · '.number_format((float) $row->amount, 2, '.', ''));
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $excludeIds
     * @return Collection<int, EmployeeMonthlyPayment>
     */
    protected static function existingPaymentsMissingPayslip(
        ?int $limit,
        array $excludeIds = [],
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): Collection {
        $query = EmployeeMonthlyPayment::query()
            ->with('employee')
            ->where(function ($builder): void {
                $builder
                    ->where('status', EmployeeMonthlyPaymentStatus::Payed->value)
                    ->orWhere('status', EmployeeMonthlyPaymentStatus::Open->value);
            })
            ->whereNull('payslip_document_id')
            ->when($excludeIds !== [], fn ($builder) => $builder->whereNotIn('id', $excludeIds))
            ->when($from !== null, fn ($q) => $q->whereDate('payment_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('payment_date', '<=', $to->toDateString()))
            ->orderBy('payment_date')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        return $query->get();
    }

    /**
     * @return array{created: bool, payslip: bool, linked: bool, skipped: bool, payment_id: int|null}
     */
    protected static function syncBankTransfer(
        BankStatementTransaction $transaction,
        Employee $employee,
        LithuanianPayrollCalculator $calculator,
        ?User $actor,
        bool $dryRun,
        bool $email,
        bool $force,
        bool $allowLocal,
    ): array {
        $net = round((float) $transaction->amount, 2);
        if ($net <= 0) {
            return ['created' => false, 'payslip' => false, 'linked' => false, 'skipped' => true, 'payment_id' => null];
        }

        $date = optional($transaction->booking_date)?->toDateString()
            ?? optional($transaction->value_date)?->toDateString();

        if ($date === null) {
            throw new RuntimeException('Bank line has no payment date.');
        }

        $payment = EmployeeMonthlyPayment::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('payment_date', $date)
            ->first();

        if ($payment !== null) {
            $existingNet = $payment->net_amount !== null ? round((float) $payment->net_amount, 2) : null;
            if ($existingNet !== null && abs($existingNet - $net) > 0.05) {
                return ['created' => false, 'payslip' => false, 'linked' => false, 'skipped' => true, 'payment_id' => (int) $payment->getKey()];
            }
        }

        if ($dryRun) {
            $needsPayslip = $payment === null || blank($payment->payslip_document_id) || $force;
            $needsLink = $payment === null
                || (int) ($transaction->employee_monthly_payment_id ?? 0) !== (int) $payment->getKey();

            return [
                'created' => $payment === null,
                'payslip' => $needsPayslip,
                'linked' => $needsLink,
                'skipped' => false,
                'payment_id' => $payment?->getKey() ? (int) $payment->getKey() : null,
            ];
        }

        $created = false;

        if ($payment === null) {
            $year = $calculator->yearFromDate($date);
            $tax = $calculator->calculateFromNet($net, $employee, year: $year);
            $comment = self::bankComment($transaction);

            $payment = EmployeeMonthlyPayment::query()->create([
                'employee_id' => $employee->getKey(),
                'payment_date' => $date,
                'base_salary' => $tax['gross'],
                'bonus_payment' => null,
                'gross_amount' => $tax['gross'],
                'npd_amount' => $tax['npd'],
                'sodra_employee_amount' => $tax['sodra_employee'],
                'sodra_employer_amount' => $tax['sodra_employer'],
                'gpm_amount' => $tax['gpm'],
                'net_amount' => $net,
                'comment' => $comment,
                'status' => EmployeeMonthlyPaymentStatus::Payed,
                'is_paid' => true,
                'paid_at' => Carbon::parse($date)->endOfDay(),
            ]);
            $created = true;
        } elseif ($payment->status !== EmployeeMonthlyPaymentStatus::Payed) {
            $payment->markPayed();
        }

        $payslipCreated = false;
        if ($force || blank($payment->payslip_document_id) || $payment->payslipDocument === null) {
            if ($force) {
                $payment->forceFill(['payslip_document_id' => null])->saveQuietly();
            }

            EmployeePayslipDocumentGenerator::createForPayment(
                $payment->fresh(['employee', 'payslipDocument']) ?? $payment,
                $actor,
                email: $email,
                allowLocalStorage: $allowLocal,
            );
            $payslipCreated = true;
        }

        $linked = false;
        if ((int) ($transaction->employee_monthly_payment_id ?? 0) !== (int) $payment->getKey()) {
            $transaction->forceFill([
                'employee_monthly_payment_id' => $payment->getKey(),
            ])->save();
            $linked = true;
        }

        return [
            'created' => $created,
            'payslip' => $payslipCreated,
            'linked' => $linked,
            'skipped' => false,
            'payment_id' => (int) $payment->getKey(),
        ];
    }

    protected static function bankComment(BankStatementTransaction $transaction): string
    {
        $label = trim((string) ($transaction->counterpart_name ?: $transaction->description ?: 'DU'));

        return 'Imported from bank statement: '.$label;
    }
}
