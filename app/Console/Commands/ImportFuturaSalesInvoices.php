<?php

namespace App\Console\Commands;

use App\Services\FuturaSalesInvoiceImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportFuturaSalesInvoices extends Command
{
    protected $signature = 'app:import-futura-sales-invoices
                            {--file= : Path to Futura sales journal XLS/XLSX}
                            {--skip-bank : Do not auto-link bank statement payments}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import Futura sales journal invoices (FTE####), link HIST orders, optionally link bank payments';

    public function handle(FuturaSalesInvoiceImporter $importer): int
    {
        $path = $this->option('file')
            ?: 'C:\\Users\\liumi\\Downloads\\Futura pardavimai 2025.01.01-2025-08-31.xls';

        try {
            $rows = $importer->loadRows($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Loaded '.count($rows).' invoice rows from '.$path);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');
            foreach (array_slice($rows, 0, 5) as $row) {
                $this->line(sprintf(
                    '  %s %s %s net=%s vat=%s gross=%s',
                    $row['invoice_number'],
                    $row['customer_code'],
                    $row['invoice_date'],
                    $row['sum_without_vat'],
                    $row['vat'],
                    $row['sum_inc_vat'],
                ));
            }

            return self::SUCCESS;
        }

        try {
            $result = $importer->import(
                path: $path,
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
            'Orders linked: %d · without matching HIST order: %d',
            $result['linked_orders'],
            $result['without_order'],
        ));
        $this->info('Bank payments linked: '.$result['bank_linked']);

        if ($result['missing_order_examples'] !== []) {
            $this->newLine();
            $this->warn('Examples without HIST order:');
            foreach ($result['missing_order_examples'] as $example) {
                $this->line('  '.$example);
            }
        }

        return self::SUCCESS;
    }
}
