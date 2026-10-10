<?php

namespace App\Console\Commands;

use App\Services\DraftOrderReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyDraftOrders extends Command
{
    protected $signature = 'app:notify-draft-orders {--date= : Base date (Y-m-d). Defaults to today.}';

    protected $description = 'Email customers every working day while their order is still in Draft.';

    public function handle(): int
    {
        $date = filled((string) ($this->option('date') ?? ''))
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        if (! DraftOrderReminder::isWorkingDay($date)) {
            $this->info('Skipped — '.$date->toDateString().' is not a working day.');

            return self::SUCCESS;
        }

        $sent = DraftOrderReminder::run($date);

        $this->info("Draft order reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
