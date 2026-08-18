<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Color;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FuturaTextilesColorImageScraper
{
    private const BASE_URL = 'https://futuratextiles.eu/portfolio_category';

    private const SWATCH_TARGET_WIDTH = 600;

    private const SWATCH_MAX_WIDTH = 800;

    /**
     * @var array<string, string> collection slug => swatch filename prefix
     */
    private const COLLECTION_PREFIXES = [
        'agnona' => 'AG',
        'argano' => 'AR',
        'borga' => 'BOR',
        'paloma' => 'PAL',
        'saramo' => 'SARA',
    ];

    /**
     * @return array<string, string> collection slug => page URL
     */
    public static function collectionUrls(): array
    {
        return [
            'agnona' => self::BASE_URL.'/agnona/',
            'argano' => self::BASE_URL.'/argano/',
            'borga' => self::BASE_URL.'/borga/',
            'paloma' => self::BASE_URL.'/paloma/',
            'saramo' => self::BASE_URL.'/saramo/',
        ];
    }

    /**
     * @return array<int, string> color code => image URL
     */
    public function scrapeCollectionPage(string $url, ?string $collectionSlug = null): array
    {
        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => 'FuturaTextilesSS/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to fetch {$url}: HTTP {$response->status()}");
        }

        $slug = $collectionSlug ?? $this->slugFromUrl($url);
        $prefix = self::COLLECTION_PREFIXES[$slug] ?? null;

        if ($prefix === null) {
            return [];
        }

        $html = $response->body();
        unset($response);

        $images = $this->extractImagesByCode($html, $prefix);
        unset($html);

        return $images;
    }

    /**
     * @return array{matched: int, downloaded: int, skipped: int, missing: list<string>}
     */
    public function scrapeCollection(Collection $collection, bool $force = false): array
    {
        $slug = Str::lower($collection->name);
        $urls = self::collectionUrls();

        if (! isset($urls[$slug])) {
            throw new RuntimeException("No Futura Textiles URL configured for collection \"{$collection->name}\".");
        }

        $imagesByCode = $this->scrapeCollectionPage($urls[$slug], $slug);
        $stats = ['matched' => 0, 'downloaded' => 0, 'skipped' => 0, 'missing' => []];

        Color::query()
            ->where('collection_id', $collection->id)
            ->orderBy('color_code')
            ->cursor()
            ->each(function (Color $color) use ($imagesByCode, $slug, $force, &$stats): void {
                $code = (string) (int) $color->color_code;

                if (! isset($imagesByCode[$code])) {
                    $stats['missing'][] = "{$slug}/{$code} ({$color->color_name})";

                    return;
                }

                $stats['matched']++;

                if (! $force && filled($color->image) && Storage::disk(Color::storageDisk())->exists($color->image)) {
                    $stats['skipped']++;

                    return;
                }

                $path = $this->downloadImage($imagesByCode[$code], $slug, $code);

                if (filled($color->image) && $color->image !== $path) {
                    Storage::disk(Color::storageDisk())->delete($color->image);
                }

                $color->update(['image' => $path]);
                $stats['downloaded']++;
            });

        return $stats;
    }

    /**
     * @return array{matched: int, downloaded: int, skipped: int, missing: list<string>}
     */
    public function scrapeAll(bool $force = false): array
    {
        $totals = ['matched' => 0, 'downloaded' => 0, 'skipped' => 0, 'missing' => []];

        Collection::query()
            ->orderBy('name')
            ->each(function (Collection $collection) use ($force, &$totals): void {
                $stats = $this->scrapeCollection($collection, $force);
                $totals['matched'] += $stats['matched'];
                $totals['downloaded'] += $stats['downloaded'];
                $totals['skipped'] += $stats['skipped'];
                $totals['missing'] = array_merge($totals['missing'], $stats['missing']);
            });

        return $totals;
    }

    /**
     * Upload packaged fallbacks and scrape only colors whose files are missing on the storage disk.
     *
     * @return array{filled_packaged: int, filled_site: int, skipped: int, still_missing: list<string>}
     */
    public function fillMissing(): array
    {
        $disk = Storage::disk(Color::storageDisk());
        $stats = [
            'filled_packaged' => 0,
            'filled_site' => 0,
            'skipped' => 0,
            'still_missing' => [],
        ];
        $needScrape = [];

        Color::query()
            ->with('collection:id,name')
            ->orderBy('collection_id')
            ->orderBy('color_code')
            ->cursor()
            ->each(function (Color $color) use ($disk, &$stats, &$needScrape): void {
                $slug = Str::lower((string) $color->collection?->name);
                $code = (string) (int) $color->color_code;
                $conventional = "colors/{$slug}/{$code}.jpg";
                $current = ltrim((string) $color->image, '/');

                if (
                    ($current !== '' && $disk->exists($current))
                    || $disk->exists($conventional)
                ) {
                    $stats['skipped']++;

                    return;
                }

                $packaged = resource_path("color-swatches/{$slug}/{$code}.jpg");

                if (is_file($packaged)) {
                    $path = $this->storeImageFromLocalPath($packaged, $slug, $code);
                    $color->update(['image' => $path]);
                    $stats['filled_packaged']++;

                    return;
                }

                $needScrape[$slug][] = $color;
            });

        foreach ($needScrape as $slug => $colors) {
            $url = self::collectionUrls()[$slug] ?? null;

            if ($url === null) {
                foreach ($colors as $color) {
                    $code = (string) (int) $color->color_code;
                    $stats['still_missing'][] = "{$slug}/{$code} ({$color->color_name})";
                }

                continue;
            }

            $imagesByCode = $this->scrapeCollectionPage($url, $slug);

            foreach ($colors as $color) {
                $code = (string) (int) $color->color_code;

                if (! isset($imagesByCode[$code])) {
                    $stats['still_missing'][] = "{$slug}/{$code} ({$color->color_name})";

                    continue;
                }

                $path = $this->downloadImage($imagesByCode[$code], $slug, $code);
                $color->update(['image' => $path]);
                $stats['filled_site']++;
            }
        }

        return $stats;
    }

    public function storeImageFromLocalPath(string $absolutePath, string $collectionSlug, string $colorCode): string
    {
        $path = "colors/{$collectionSlug}/{$colorCode}.jpg";
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Failed to read {$absolutePath}.");
        }

        try {
            $this->writeImageStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * @return array<int, string> color code => image URL
     */
    private function extractImagesByCode(string $html, string $prefix): array
    {
        $pattern = '/((?:https?:)?\/\/futuratextiles\.eu\/wp-content\/uploads\/[^"\'\s]+?\/'
            .preg_quote($prefix, '/')
            .'_(\d+)_[A-Z0-9_\-]+)((?:-\d+x\d+)?\.(?:jpg|jpeg|png|webp))/i';

        if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $candidates = [];

        foreach ($matches as $match) {
            $code = (string) (int) $match[2];
            $url = $this->absoluteUrl($match[1].$match[3]);
            $score = $this->swatchScore($url);

            if (! isset($candidates[$code]) || $score > $candidates[$code]['score']) {
                $candidates[$code] = ['url' => $url, 'score' => $score];
            }
        }

        $images = [];

        foreach ($candidates as $code => $candidate) {
            $images[$code] = $candidate['url'];
        }

        return $images;
    }

    private function slugFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segment = trim((string) Str::afterLast(rtrim($path, '/'), '/'));

        return Str::lower($segment);
    }

    private function absoluteUrl(string $url): string
    {
        $url = html_entity_decode(trim($url));

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with($url, '/')) {
            return 'https://futuratextiles.eu'.$url;
        }

        return $url;
    }

    private function swatchScore(string $url): int
    {
        if (! preg_match('/-(\d+)x(\d+)\.(?:jpg|jpeg|png|webp)$/i', $url, $match)) {
            return 0;
        }

        $width = (int) $match[1];

        if ($width < 200 || $width > self::SWATCH_MAX_WIDTH) {
            return max(1, 100 - abs($width - self::SWATCH_TARGET_WIDTH));
        }

        return 1000 - abs($width - self::SWATCH_TARGET_WIDTH);
    }

    private function downloadImage(string $url, string $collectionSlug, string $colorCode): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
        $extension = Str::lower($extension);
        $path = "colors/{$collectionSlug}/{$colorCode}.{$extension}";
        $tmpPath = tempnam(sys_get_temp_dir(), 'ft-color-');

        if ($tmpPath === false) {
            throw new RuntimeException('Failed to create a temporary file for image download.');
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['User-Agent' => 'FuturaTextilesSS/1.0'])
                ->sink($tmpPath)
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to download {$url}: HTTP {$response->status()}");
            }

            unset($response);

            $stream = fopen($tmpPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Failed to read downloaded image for {$url}.");
            }

            try {
                $this->writeImageStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }

        return $path;
    }

    /**
     * @param  resource  $stream
     */
    private function writeImageStream(string $path, $stream): void
    {
        $disk = Color::storageDisk();
        $options = $disk === 's3' ? ['visibility' => 'public'] : [];
        Storage::disk($disk)->writeStream($path, $stream, $options);
    }
}
