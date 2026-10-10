<?php

namespace App\Console\Commands;

use App\Services\HistoricalCompanyOrderImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportHistoricalCompanyOrders extends Command
{
    protected $signature = 'app:import-historical-company-orders
                            {--dir= : Directory containing the Word įsakymai (default: user Downloads)}
                            {--dry-run : Resolve files and report planned actions only}
                            {--skip-documents : Create DB entries without uploading to SharePoint}';

    protected $description = 'Import historical company orders (įsakymai): SharePoint docs, leave requests, termination sync';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $directory = $dir ?: rtrim((string) getenv('USERPROFILE'), '\\/').DIRECTORY_SEPARATOR.'Downloads';
            foreach (HistoricalCompanyOrderImporter::definitions() as $definition) {
                $path = HistoricalCompanyOrderImporter::resolveFile($directory, $definition['file_match']);
                $this->info(sprintf(
                    'Nr. %d %s — %s',
                    $definition['number'],
                    $definition['date'],
                    $path ? basename($path) : 'MISSING',
                ));
                foreach ($definition['leaves'] ?? [] as $leave) {
                    $this->line(sprintf(
                        '  leave: %s %s %s → %s',
                        $leave['employee'],
                        $leave['type'],
                        $leave['from'],
                        $leave['to'],
                    ));
                }
                if (isset($definition['termination'])) {
                    $t = $definition['termination'];
                    $this->line(sprintf(
                        '  termination: %s on %s',
                        $t['employee'],
                        $t['date'],
                    ));
                }
            }

            return self::SUCCESS;
        }

        try {
            $results = HistoricalCompanyOrderImporter::import(
                $dir,
                ! (bool) $this->option('skip-documents'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->info(sprintf(
                'Nr. %d → document #%s (%s)',
                $result['number'],
                $result['document_id'] ?? '—',
                basename($result['path']),
            ));
            foreach ($result['leaves'] as $leave) {
                $this->line(sprintf(
                    '  leave #%d %s %s→%s (%s)',
                    $leave['leave_request_id'],
                    $leave['employee'],
                    $leave['from'],
                    $leave['to'],
                    $leave['type'],
                ));
            }
            if ($result['termination'] !== null) {
                $t = $result['termination'];
                $this->line(sprintf(
                    '  termination employee #%d contract #%d date %s',
                    $t['employee_id'],
                    $t['contract_id'],
                    $t['date'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
