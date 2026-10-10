<?php

namespace App\Console\Commands;

use App\Services\HistoricalEmployeePayslipGenerator;
use Illuminate\Console\Command;

class GenerateHistoricalPayslips extends Command
{
    protected $signature = 'app:generate-historical-payslips
                            {--dry-run : Report matches without creating payments or PDFs}
                            {--email : Email generated payslips to employees}
                            {--force : Regenerate payslips even if one already exists}
                            {--limit= : Process at most N bank salary lines}
                            {--from= : Start month YYYY-MM}
                            {--to= : End month YYYY-MM}';

    protected $description = 'Create DU payments and atsiskaitymo lapeliai from historical bank salary (DU) transfers';

    public function handle(): int
    {
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;

        $result = HistoricalEmployeePayslipGenerator::run(
            actor: auth()->user(),
            dryRun: (bool) $this->option('dry-run'),
            email: (bool) $this->option('email'),
            force: (bool) $this->option('force'),
            limit: $limit,
            fromMonth: filled($this->option('from')) ? (string) $this->option('from') : null,
            toMonth: filled($this->option('to')) ? (string) $this->option('to') : null,
        );

        $prefix = $this->option('dry-run') ? 'Would ' : '';
        $this->info($prefix.'create '.$result['created'].' payment(s), generate '.$result['payslips'].' payslip(s), link '.$result['linked'].' bank line(s).');

        if ($result['skipped'] > 0) {
            $this->warn('Skipped '.$result['skipped'].' line(s) (existing payment amount differs).');
        }

        foreach ($result['unmatched'] as $label) {
            $this->warn('Unmatched DU payee: '.$label);
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
