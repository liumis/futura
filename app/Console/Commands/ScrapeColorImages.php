<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Services\FuturaTextilesColorImageScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ScrapeColorImages extends Command
{
    protected $signature = 'colors:scrape-images
                            {--collection= : Scrape a single collection by name (e.g. Agnona)}
                            {--force : Re-download images even if already stored}';

    protected $description = 'Download color swatch images from futuratextiles.eu into storage (S3 on Cloud)';

    public function handle(FuturaTextilesColorImageScraper $scraper): int
    {
        $force = (bool) $this->option('force');
        $collectionName = $this->option('collection');

        if (filled($collectionName)) {
            $collection = Collection::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower((string) $collectionName)])
                ->first();

            if ($collection === null) {
                $this->error("Collection \"{$collectionName}\" not found.");

                return self::FAILURE;
            }

            $stats = $scraper->scrapeCollection($collection, $force);
            $this->reportStats($collection->name, $stats);

            return $stats['missing'] === [] ? self::SUCCESS : self::SUCCESS;
        }

        $this->info('Scraping all Futura Textiles collection pages...');
        $totals = ['matched' => 0, 'downloaded' => 0, 'skipped' => 0, 'missing' => []];

        Collection::query()
            ->orderBy('name')
            ->each(function (Collection $collection) use ($scraper, $force, &$totals): void {
                $this->info("Scraping {$collection->name}...");
                $stats = $scraper->scrapeCollection($collection, $force);
                $this->reportStats($collection->name, $stats);

                $totals['matched'] += $stats['matched'];
                $totals['downloaded'] += $stats['downloaded'];
                $totals['skipped'] += $stats['skipped'];
                $totals['missing'] = array_merge($totals['missing'], $stats['missing']);
            });

        $this->reportStats('All collections', $totals);

        return self::SUCCESS;
    }

    /**
     * @param  array{matched: int, downloaded: int, skipped: int, missing: list<string>}  $stats
     */
    private function reportStats(string $label, array $stats): void
    {
        $this->newLine();
        $this->info("{$label}: {$stats['downloaded']} downloaded, {$stats['skipped']} skipped, {$stats['matched']} matched.");

        if ($stats['missing'] !== []) {
            $this->warn(count($stats['missing']).' colors had no image on the source page:');
            foreach ($stats['missing'] as $missing) {
                $this->line("  - {$missing}");
            }
        }
    }
}
