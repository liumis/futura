<?php

namespace App\Console\Commands;

use App\Services\FuturaSalesInvoicePdfImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportFuturaSalesInvoicePdfs extends Command
{
    protected $signature = 'app:import-futura-sales-invoice-pdfs
                            {--dir= : Folder with FTE*.pdf files (default: Downloads)}
                            {--september : Only FTE0148–FTE0163 (2026-09)}
                            {--skip-bank : Do not auto-link bank statement payments}
                            {--skip-sharepoint : Do not upload PDFs to SharePoint}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import Futura FTE sales invoice PDFs into HIST orders + invoices, optionally link bank payments';

    public function handle(FuturaSalesInvoicePdfImporter $importer): int
    {
        $dir = $this->option('dir') ?: 'C:\\Users\\liumi\\Downloads';

        $paths = $this->option('september')
            ? FuturaSalesInvoicePdfImporter::discoverSeptember2026Paths($dir)
            : FuturaSalesInvoicePdfImporter::discoverPdfPaths($dir);

        if ($paths === []) {
            $this->error('No FTE*.pdf files found in '.$dir);

            return self::FAILURE;
        }

        $this->info('Found '.count($paths).' PDF(s) in '.$dir);

        if ($this->option('dry-run')) {
            foreach ($paths as $path) {
                try {
                    $parsed = $importer->parsePdf($path);
                    $this->line(sprintf(
                        '  %s %s %s net=%s vat=%s gross=%s lines=%d code=%s',
                        $parsed['invoice_number'],
                        $parsed['invoice_date'],
                        $parsed['customer_name'],
                        number_format($parsed['sum_without_vat'], 2, '.', ''),
                        number_format($parsed['vat'], 2, '.', ''),
                        number_format($parsed['sum_inc_vat'], 2, '.', ''),
                        count($parsed['lines']),
                        $parsed['customer_code'],
                    ));
                } catch (Throwable $exception) {
                    $this->error('  '.basename($path).': '.$exception->getMessage());
                }
            }

            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->importPaths(
                paths: $paths,
                linkBank: ! (bool) $this->option('skip-bank'),
                uploadSharepoint: ! (bool) $this->option('skip-sharepoint'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Invoices: created %d, skipped existing %d (of %d files)',
            $result['created'],
            $result['skipped_existing'],
            $result['files'],
        ));
        $this->info(sprintf(
            'Orders: created %d, reused %d · line items %d',
            $result['orders_created'],
            $result['orders_reused'],
            $result['items_created'],
        ));
        $this->info(sprintf(
            'SharePoint uploads: %d · Bank payments linked: %d',
            $result['sharepoint'],
            $result['bank_linked'],
        ));

        foreach ($result['errors'] as $error) {
            $this->error('  '.$error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
