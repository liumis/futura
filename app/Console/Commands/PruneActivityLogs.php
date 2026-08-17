<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

class PruneActivityLogs extends Command
{
    protected $signature = 'app:prune-activity-logs
                            {--years= : Retention in years (default: '.ActivityLogger::RETENTION_YEARS.')}
                            {--dry-run : Only report how many rows would be deleted}';

    protected $description = 'Delete activity logs older than the retention period (default 3 years)';

    public function handle(): int
    {
        $years = (int) ($this->option('years') ?: ActivityLogger::RETENTION_YEARS);

        if ($years < 1) {
            $this->error('Years must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subYears($years);
        $query = ActivityLog::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No activity logs older than '.$cutoff->toDateTimeString().'.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} activity log(s) older than {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(500, function ($rows) use (&$deleted): void {
            $ids = $rows->pluck('id')->all();
            $deleted += ActivityLog::query()->whereIn('id', $ids)->delete();
        });

        ActivityLogger::log(
            'system.activity_logs_pruned',
            "Pruned {$deleted} activity log(s) older than {$years} year(s).",
            properties: [
                'deleted' => $deleted,
                'years' => $years,
                'cutoff' => $cutoff->toIso8601String(),
            ],
            allowWithoutUser: true,
        );

        $this->info("Deleted {$deleted} activity log(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
