<?php

namespace App\Console\Commands;

use App\Services\DirectoryContactExcelImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportDirectoryContacts extends Command
{
    protected $signature = 'app:import-directory-contacts {path : Path to Futura CRM Excel (.xlsx)}';

    protected $description = 'Import directory contacts (CRM cards) from an Excel file';

    public function handle(DirectoryContactExcelImporter $importer): int
    {
        $path = $this->argument('path');

        try {
            $result = $importer->import($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %s — created %d, updated %d, skipped %d, persons %d, comments %d.',
            $result['file_name'],
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['persons'],
            $result['comments'],
        ));

        return self::SUCCESS;
    }
}
