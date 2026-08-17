<?php

namespace App\Console\Commands;

use App\Services\DokobitSigningSyncer;
use Illuminate\Console\Command;

class SyncDokobitSignings extends Command
{
    protected $signature = 'app:sync-dokobit-signings';

    protected $description = 'Poll Dokobit for pending signings and download completed signed PDFs into Documents';

    public function handle(): int
    {
        $result = DokobitSigningSyncer::syncPending();

        $this->info(sprintf(
            'Dokobit sync: checked %d, completed %d, failed %d.',
            $result['checked'],
            $result['completed'],
            $result['failed'],
        ));

        return $result['failed'] > 0 && $result['completed'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
