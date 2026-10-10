<?php

namespace App\Console\Commands;

use App\Services\DuPayrollJournalExcelImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportDuPayrollJournals extends Command
{
    protected $signature = 'app:import-du-payroll-journals
                            {paths?* : Absolute paths to DU journal Excel files}
                            {--generate : Also generate payslips, journal PDF, GPM313, and link bank lines}';

    protected $description = 'Import Darbo užmokesčio priskaitymo žiniaraštis Excel into employee monthly payments';

    public function handle(): int
    {
        $paths = $this->argument('paths');
        if ($paths === []) {
            $this->error('Pass one or more Excel paths.');

            return self::FAILURE;
        }

        $months = [];

        foreach ($paths as $path) {
            $path = (string) $path;
            $this->info('Importing '.basename($path));

            try {
                $result = DuPayrollJournalExcelImporter::import($path);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $key = sprintf('%04d-%02d', $result['year'], $result['month']);
            $months[$key] = true;

            $this->info(sprintf(
                '  %s: created %d, updated %d, payments %d',
                $key,
                $result['created'],
                $result['updated'],
                $result['payments']->count(),
            ));

            foreach ($result['payments'] as $payment) {
                $this->line(sprintf(
                    '    #%d %s net=%s gross=%s',
                    $payment->getKey(),
                    $payment->employee?->fullName() ?? '?',
                    number_format((float) $payment->net_amount, 2, '.', ''),
                    number_format((float) $payment->gross_amount, 2, '.', ''),
                ));
            }

            foreach ($result['unmatched'] as $name) {
                $this->warn('  Unmatched employee: '.$name);
            }
        }

        if (! $this->option('generate') || $months === []) {
            return self::SUCCESS;
        }

        $keys = array_keys($months);
        sort($keys);

        return $this->call('app:generate-payroll-period', [
            '--from' => $keys[0],
            '--to' => $keys[array_key_last($keys)],
        ]);
    }
}
