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

        return $this->extractImagesByCode($response->body(), $prefix);
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

        $images = [];

        foreach ($matches as $match) {
            $code = (string) (int) $match[2];
            $url = $this->absoluteUrl($match[1].$match[3]);
            $normalizedUrl = $this->normalizeImageUrl($url);

            if (! isset($images[$code])) {
                $images[$code] = $normalizedUrl;

                continue;
            }

            if ($this->imageSizeScore($url) > $this->imageSizeScore($images[$code])) {
                $images[$code] = $normalizedUrl;
            }
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

    private function normalizeImageUrl(string $url): string
    {
        $url = $this->absoluteUrl($url);

        return preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $url) ?? $url;
    }

    private function imageSizeScore(string $url): int
    {
        if (preg_match('/-(\d+)x(\d+)\.(?:jpg|jpeg|png|webp)$/i', $url, $match)) {
            return (int) $match[1] * (int) $match[2];
        }

        return PHP_INT_MAX;
    }

    private function downloadImage(string $url, string $collectionSlug, string $colorCode): string
    {
        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => 'FuturaTextilesSS/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download {$url}: HTTP {$response->status()}");
        }

        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
        $extension = Str::lower($extension);
        $path = "colors/{$collectionSlug}/{$colorCode}.{$extension}";

        $disk = Color::storageDisk();
        $options = $disk === 's3' ? ['visibility' => 'public'] : [];

        Storage::disk($disk)->put($path, $response->body(), $options);

        return $path;
    }
}
