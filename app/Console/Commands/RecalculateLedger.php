<?php

namespace App\Console\Commands;

use App\Services\LedgerRecalculator;
use Illuminate\Console\Command;
use Throwable;

class RecalculateLedger extends Command
{
    protected $signature = 'app:recalculate-ledger
                            {--from=2026-04-01 : Recalculate invoices and payroll GL from this date (opening stays zero)}
                            {--coverage-only : Only print coverage stats}';

    protected $description = 'Recalculate Didžioji knyga finance lines and payroll accruals from a start date';

    public function handle(): int
    {
        $from = (string) $this->option('from');

        try {
            if ($this->option('coverage-only')) {
                $coverage = LedgerRecalculator::coverage($from);
                $this->table(array_keys($coverage), [array_values($coverage)]);

                return self::SUCCESS;
            }

            $this->info('Recalculating ledger from '.$from.' (opening balances = zero)…');
            $result = LedgerRecalculator::recalculate($from);

            $this->info(sprintf(
                'Expense types mapped: %d · invoices regenerated: %d (%d lines) · payroll months: %d (%d GL invoices, %d lines)',
                $result['expense_types_mapped'],
                $result['invoices'],
                $result['invoice_lines'],
                $result['payroll_months'],
                $result['payroll_invoices'],
                $result['payroll_lines'],
            ));

            $this->table(array_keys($result['coverage']), [array_values($result['coverage'])]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
