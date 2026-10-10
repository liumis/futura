<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\NotificationLog;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PruneActivityLogs extends Command
{
    protected $signature = 'app:prune-activity-logs
                            {--years= : Retention in years (default: '.ActivityLogger::RETENTION_YEARS.')}
                            {--dry-run : Only report how many rows would be deleted}';

    protected $description = 'Delete activity, notification, and email logs older than the retention period (default 3 years)';

    public function handle(): int
    {
        $years = (int) ($this->option('years') ?: ActivityLogger::RETENTION_YEARS);

        if ($years < 1) {
            $this->error('Years must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subYears($years);
        $dryRun = (bool) $this->option('dry-run');
        $totals = [];

        foreach ([
            'activity logs' => ActivityLog::class,
            'notification logs' => NotificationLog::class,
            'email logs' => EmailLog::class,
        ] as $label => $modelClass) {
            $totals[$label] = $this->pruneModel($modelClass, $cutoff, $dryRun);

            $count = $totals[$label];
            $verb = $dryRun ? 'Would delete' : 'Deleted';
            $this->info("{$verb} {$count} {$label} older than {$cutoff->toDateTimeString()}.");
        }

        $deleted = array_sum($totals);

        if (! $dryRun && $deleted > 0) {
            ActivityLogger::log(
                'system.logs_pruned',
                "Pruned {$deleted} log(s) older than {$years} year(s).",
                properties: [
                    'deleted' => $totals,
                    'years' => $years,
                    'cutoff' => $cutoff->toIso8601String(),
                ],
                allowWithoutUser: true,
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function pruneModel(string $modelClass, \DateTimeInterface $cutoff, bool $dryRun): int
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0 || $dryRun) {
            return $count;
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(500, function ($rows) use ($modelClass, &$deleted): void {
            $ids = $rows->pluck('id')->all();
            $deleted += $modelClass::query()->whereIn('id', $ids)->delete();
        });

        return $deleted;
    }
}
