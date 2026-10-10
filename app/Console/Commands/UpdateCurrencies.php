<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;

class UpdateCurrencies extends Command
{
    protected $signature = 'app:update-currencies';

    protected $description = 'Import the latest euro reference rates from Lietuvos bankas';

    public function handle(): int
    {
        try {
            $count = Currency::importFromLbBank();
        } catch (\Throwable $exception) {
            $this->error('Currency import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Updated {$count} currencies from Lietuvos bankas.");

        return self::SUCCESS;
    }
}
