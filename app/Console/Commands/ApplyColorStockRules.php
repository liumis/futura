<?php

namespace App\Console\Commands;

use App\Services\ColorReorderPolicy;
use Illuminate\Console\Command;

class ApplyColorStockRules extends Command
{
    protected $signature = 'colors:apply-stock-rules {--dry-run : Count matches without writing}';

    protected $description = 'Apply Darbai STOCK_RULES to colors (recommended_stock / default_order)';

    public function handle(): int
    {
        $path = base_path(ColorReorderPolicy::DATA_PATH);
        if (! is_file($path)) {
            $this->error('Missing '.ColorReorderPolicy::DATA_PATH);

            return self::FAILURE;
        }

        ColorReorderPolicy::clearCache();

        $dryRun = (bool) $this->option('dry-run');
        $stats = ColorReorderPolicy::applyStockRules($dryRun);

        $this->info(($dryRun ? '[dry-run] ' : '').'STOCK_RULES applied from '.ColorReorderPolicy::DATA_PATH);
        $this->table(
            ['Metric', 'Count'],
            [
                ['Rules in file', $stats['rules']],
                ['Colors scanned', $stats['colors']],
                ['Matched', $stats['matched']],
                ['Updated', $stats['updated']],
                ['No-mark zeroed', $stats['nomark']],
                ['Skipped (no match)', $stats['skipped']],
            ],
        );

        return self::SUCCESS;
    }
}
