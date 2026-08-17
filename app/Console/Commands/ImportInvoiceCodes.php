<?php

namespace App\Console\Commands;

use App\Services\InvoiceCodeImporter;
use Illuminate\Console\Command;

class ImportInvoiceCodes extends Command
{
    protected $signature = 'app:import-invoice-codes {path? : Path to the invoice codes text file}';

    protected $description = 'Import invoice codes from a text file';

    public function handle(): int
    {
        $path = $this->argument('path') ?? database_path('data/invoice_codes.txt');

        try {
            $result = InvoiceCodeImporter::importFromFile($path);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d codes (%d created, %d updated).',
            $result['total'],
            $result['created'],
            $result['updated'],
        ));

        return self::SUCCESS;
    }
}
