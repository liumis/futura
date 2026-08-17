<?php

namespace App\Console\Commands;

use App\Services\LowStockNotifier;
use Illuminate\Console\Command;

class NotifyLowStock extends Command
{
    protected $signature = 'app:notify-low-stock';

    protected $description = 'Notify opted-in users about products below the low stock alert limit';

    public function handle(): int
    {
        $notified = LowStockNotifier::run();

        $this->info("Low stock notifications sent to {$notified} user(s).");

        return self::SUCCESS;
    }
}
