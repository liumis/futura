<?php

namespace App\Console\Commands;

use App\Services\UnbalancedFinanceMonths;
use App\Services\UnbalancedFinanceMonthsFixer;
use Illuminate\Console\Command;

class FixUnbalancedFinanceMonths extends Command
{
    protected $signature = 'app:fix-unbalanced-finance-months';

    protected $description = 'Repair duplicate/misdirected own-account bank legs and broken invoice journals';

    public function handle(): int
    {
        $before = UnbalancedFinanceMonths::all()->count();
        $stats = UnbalancedFinanceMonthsFixer::run();
        $after = UnbalancedFinanceMonths::all()->count();

        $this->info(sprintf(
            'Unbalanced months: %d → %d (july_deleted=%d, september_flipped=%d, august_mirrored=%d, journals_rebuilt=%d)',
            $before,
            $after,
            $stats['july_deleted'],
            $stats['september_flipped'],
            $stats['august_mirrored'],
            $stats['journals_rebuilt'],
        ));

        return self::SUCCESS;
    }
}
