<?php

namespace App\Console\Commands;

use App\Services\FuturaAddressBookCustomerImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportFuturaAddressBookCustomers extends Command
{
    protected $signature = 'app:import-futura-address-book-customers';

    protected $description = 'Import Futura address book companies into customer users';

    public function handle(FuturaAddressBookCustomerImporter $importer): int
    {
        try {
            $result = $importer->import();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Customers import finished — created %d, updated %d, skipped %d.',
            $result['created'],
            $result['updated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
