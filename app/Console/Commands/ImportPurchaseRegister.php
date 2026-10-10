<?php

namespace App\Console\Commands;

use App\Services\PurchaseRegisterImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportPurchaseRegister extends Command
{
    protected $signature = 'app:import-purchase-register
                            {--file= : Path to Pirkimai / purchase register PDF}
                            {--dir= : Folder with matching supplier invoice PDFs}
                            {--skip-bank : Do not auto-link bank payments}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import purchase register (Pirkimai) rows as expense invoices, attach PDFs, link bank';

    public function handle(PurchaseRegisterImporter $importer): int
    {
        $path = $this->option('file') ?: 'C:\\Users\\liumi\\Downloads\\Pirkimai 2026.09.pdf';
        $dir = $this->option('dir') ?: 'C:\\Users\\liumi\\Downloads';

        try {
            $rows = $importer->loadRows($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Parsed '.count($rows).' purchase rows from '.$path);

        if ($this->option('dry-run')) {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '  %s %s %s net=%s vat=%s gross=%s',
                    $row['invoice_number'],
                    $row['invoice_date'],
                    $row['supplier_name'],
                    number_format($row['sum_without_vat'], 2, '.', ''),
                    number_format($row['vat'], 2, '.', ''),
                    number_format($row['sum_inc_vat'], 2, '.', ''),
                ));
            }
            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import(
                registerPath: $path,
                pdfSearchDirs: [$dir],
                linkBank: ! (bool) $this->option('skip-bank'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Invoices: created %d, skipped existing %d (of %d rows)',
            $result['created'],
            $result['skipped_existing'],
            $result['rows'],
        ));
        $this->info(sprintf(
            'PDFs attached: %d · SharePoint: %d · Bank linked: %d',
            $result['with_pdf'],
            $result['sharepoint'],
            $result['bank_linked'],
        ));

        if ($result['missing_pdf'] !== []) {
            $this->newLine();
            $this->warn('No PDF matched (invoice still created):');
            foreach ($result['missing_pdf'] as $line) {
                $this->line('  '.$line);
            }
        }

        foreach ($result['errors'] as $error) {
            $this->error('  '.$error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
