<?php

namespace App\Console\Commands;

use App\Services\TabelisTimesheetImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportHistoricalTabelis extends Command
{
    protected $signature = 'app:import-historical-tabelis
                            {paths?* : Absolute paths to Tabelis Excel/xlsm files}
                            {--dry-run : Parse and report only; do not write schedules or SharePoint}
                            {--skip-documents : Import schedules without uploading source files}';

    protected $description = 'Import historical Darbo laiko apskaitos žiniaraštis (Tabelis) into work schedules and SharePoint';

    public function handle(): int
    {
        $paths = $this->argument('paths');
        if ($paths === []) {
            $downloads = rtrim((string) getenv('USERPROFILE'), '\\/').DIRECTORY_SEPARATOR.'Downloads';
            $defaults = [
                'Tabelis 2026 04.xls',
                'Tabelis 2026 05.xls',
                'Tabelis 2026 06.xlsm',
                'Tabelis 2026 07.xlsm',
                'Tabelis 2026 08_1.xlsm',
                'Tabelis 2026 09.xlsm',
            ];
            foreach ($defaults as $name) {
                $full = $downloads.DIRECTORY_SEPARATOR.$name;
                if (is_file($full)) {
                    $paths[] = $full;
                }
            }
        }

        if ($paths === []) {
            $this->error('Pass one or more Tabelis Excel paths (or place default files in Downloads).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $storeDocuments = ! $dryRun && ! (bool) $this->option('skip-documents');

        foreach ($paths as $path) {
            $path = (string) $path;
            $this->info(($dryRun ? '[dry-run] ' : '').'Importing '.basename($path));

            try {
                if ($dryRun) {
                    [$year, $month] = TabelisTimesheetImporter::yearMonthFromFilename($path);
                    [$parsed, $unmatched] = TabelisTimesheetImporter::parseFile($path, $year, $month);
                    $this->info(sprintf('  %04d-%02d: %d employees', $year, $month, count($parsed)));
                    foreach ($parsed as $row) {
                        $actual = round(collect($row['entries'])->sum(fn (array $e): float => (float) $e['actual_hours']), 2);
                        $planned = round(collect($row['entries'])->sum(fn (array $e): float => (float) $e['hours']), 2);
                        $this->line(sprintf(
                            '    %s planned=%s actual=%s days=%d',
                            $row['employee']->fullName(),
                            number_format($planned, 2, '.', ''),
                            number_format($actual, 2, '.', ''),
                            count($row['entries']),
                        ));
                    }
                    foreach ($unmatched as $name) {
                        $this->warn('  Unmatched: '.$name);
                    }

                    continue;
                }

                $result = TabelisTimesheetImporter::import($path, $storeDocuments);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($dryRun) {
                continue;
            }

            $this->info(sprintf(
                '  %04d-%02d: %d employees%s',
                $result['year'],
                $result['month'],
                count($result['employees']),
                $result['document_id'] ? ', document #'.$result['document_id'] : '',
            ));

            foreach ($result['employees'] as $row) {
                $this->line(sprintf(
                    '    #%d %s planned=%s actual=%s',
                    $row['employee_id'],
                    $row['name'],
                    number_format($row['planned_hours'], 2, '.', ''),
                    number_format($row['actual_hours'], 2, '.', ''),
                ));
            }

            foreach ($result['unmatched'] as $name) {
                $this->warn('  Unmatched: '.$name);
            }
        }

        return self::SUCCESS;
    }
}
