<?php

namespace App\Console\Commands;

use App\Services\FuturaTextilesColorImageScraper;
use Illuminate\Console\Command;

class FillMissingColorImages extends Command
{
    protected $signature = 'colors:fill-missing';

    protected $description = 'Upload packaged fallbacks and scrape only color images missing from storage';

    public function handle(FuturaTextilesColorImageScraper $scraper): int
    {
        $this->info('Filling color images that are missing on the storage disk...');
        $stats = $scraper->fillMissing();

        $this->info("Packaged fallbacks uploaded: {$stats['filled_packaged']}");
        $this->info("Downloaded from website: {$stats['filled_site']}");
        $this->info("Already present: {$stats['skipped']}");

        if ($stats['still_missing'] !== []) {
            $this->warn(count($stats['still_missing']).' colors still have no image:');
            foreach ($stats['still_missing'] as $missing) {
                $this->line("  - {$missing}");
            }
        }

        return $stats['still_missing'] === [] ? self::SUCCESS : self::SUCCESS;
    }
}
