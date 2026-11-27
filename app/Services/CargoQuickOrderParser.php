<?php

namespace App\Services;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Color;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use Illuminate\Support\Collection;

class CargoQuickOrderParser
{
    /** Default roll length when product size is unknown (meters). */
    public const DEFAULT_ROLL_METERS = 25.0;

    /** Bare qty ≥ this (no unit) is treated as meters, not rolls. */
    public const METERS_QTY_THRESHOLD = 25.0;

    public function __construct(
        protected ExternalStockExcelParser $codes = new ExternalStockExcelParser,
    ) {}

    /**
     * @return list<array{
     *     product_id: ?int,
     *     code: string,
     *     label: string,
     *     amount: int,
     *     unit: string,
     *     matched: bool,
     *     stock: int|null,
     *     size_meters: float|null,
     *     detail: string,
     *     confidence: string,
     *     needs_confirmation: bool,
     *     confirmed: bool,
     *     warning: string
     * }>
     */
    public function parse(string $text, ?int $supplierId = null): array
    {
        $products = $this->productsForSupplier($supplierId);
        $index = $this->buildIndex($products);
        $collections = $this->knownCollections($products);
        $candidates = $this->extractCandidates($text, $collections);

        if ($candidates === []) {
            return [];
        }

        $merged = [];

        foreach ($candidates as $candidate) {
            $match = $this->matchProductDetailed(
                $candidate['code'],
                $index,
                $products,
                $collections,
                (string) ($candidate['collection_hint'] ?? ''),
                (string) ($candidate['collection_confidence'] ?? 'high'),
            );

            $product = $match['product'];
            $amount = $this->normalizeAmount(
                $candidate['amount'],
                $candidate['unit'],
                $product,
            );

            if ($amount <= 0) {
                continue;
            }

            $key = $product?->getKey() ?? ('unmatched:'.$this->codes->normalizeCode($candidate['code']));
            $confidence = $product ? $match['confidence'] : 'none';
            $needsConfirmation = $product !== null && in_array($confidence, ['medium', 'low'], true);
            $warning = $product ? $match['warning'] : 'Not matched — check code / collection';

            if (! isset($merged[$key])) {
                $size = $product?->sizeMeters();
                $merged[$key] = [
                    'product_id' => $product?->getKey(),
                    'code' => $product?->alternative_code
                        ?: $product?->product_code
                        ?: $candidate['code'],
                    'label' => $product
                        ? $this->productLabel($product)
                        : 'Not found: '.$candidate['code'],
                    'amount' => 0,
                    'unit' => $product?->sizeMeters() ? 'rolls' : 'vnt',
                    'matched' => $product !== null,
                    'stock' => $product ? (int) $product->current_amount : null,
                    'size_meters' => $size,
                    'detail' => '',
                    'confidence' => $confidence,
                    'needs_confirmation' => $needsConfirmation,
                    'confirmed' => false,
                    'warning' => $warning,
                ];
            } else {
                $merged[$key]['confidence'] = $this->worseConfidence(
                    (string) $merged[$key]['confidence'],
                    $confidence,
                );
                $merged[$key]['needs_confirmation'] = $merged[$key]['matched']
                    && in_array($merged[$key]['confidence'], ['medium', 'low'], true);
                if ($warning !== '' && $merged[$key]['warning'] === '') {
                    $merged[$key]['warning'] = $warning;
                } elseif ($warning !== '' && ! str_contains((string) $merged[$key]['warning'], $warning)) {
                    $merged[$key]['warning'] = trim($merged[$key]['warning'].'; '.$warning);
                }
            }

            $merged[$key]['amount'] += $amount;
        }

        return array_values(array_map(function (array $line): array {
            $line['detail'] = $this->detailLine($line);

            return $line;
        }, $merged));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function summary(array $lines): string
    {
        $rows = ['Užsakymas:'];

        foreach ($lines as $line) {
            if (! ($line['matched'] ?? false) || (int) ($line['amount'] ?? 0) <= 0) {
                continue;
            }

            $suffix = '';
            if (($line['needs_confirmation'] ?? false) && ! ($line['confirmed'] ?? false)) {
                $suffix = ' ⚠';
            }

            $rows[] = sprintf(
                '%s — %d %s%s',
                (string) ($line['code'] ?? ''),
                (int) $line['amount'],
                (string) ($line['unit'] ?? 'vnt'),
                $suffix,
            );
        }

        if (count($rows) === 1) {
            $rows[] = '(no matched items)';
        }

        return implode("\n", $rows);
    }

    /**
     * Lines that still need an explicit confirm before apply.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function unconfirmedSoftMatches(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            fn (array $line): bool => ($line['matched'] ?? false)
                && ($line['needs_confirmation'] ?? false)
                && ! ($line['confirmed'] ?? false)
                && (int) ($line['amount'] ?? 0) > 0,
        ));
    }

    /**
     * @param  array<string, string>  $collections  normalized => display name
     * @return list<array{code: string, amount: float, unit: string, collection_hint?: string, collection_confidence?: string}>
     */
    protected function extractCandidates(string $text, array $collections = []): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split("/\n+/", $text) ?: [];
        $candidates = [];
        $currentCollection = null;
        $currentCollectionConfidence = 'high';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(užsakymas|order|quantity|kiekis|product|preke)\b/iu', $line)) {
                continue;
            }

            $header = $this->matchCollectionHeader($line, $collections);
            if ($header !== null) {
                $currentCollection = $header['name'];
                $currentCollectionConfidence = $header['confidence'];

                continue;
            }

            if ($currentCollection !== null) {
                $underCollection = $this->parseColorQtyLine($line);
                if ($underCollection !== null) {
                    $candidates[] = [
                        'code' => $currentCollection.' '.$underCollection['color'],
                        'amount' => $underCollection['amount'],
                        'unit' => $underCollection['unit'],
                        'collection_hint' => $currentCollection,
                        'collection_confidence' => $currentCollectionConfidence,
                    ];

                    continue;
                }
            }

            // Bare color code / short token under an active collection
            if ($currentCollection !== null && preg_match('/^(?P<color>\d{1,8}|[A-Za-z][A-Za-z0-9 ]{0,40})$/u', $line, $m)) {
                $candidates[] = [
                    'code' => $currentCollection.' '.trim($m['color']),
                    'amount' => 1.0,
                    'unit' => '',
                    'collection_hint' => $currentCollection,
                    'collection_confidence' => $currentCollectionConfidence,
                ];

                continue;
            }

            if (mb_strlen($line) < 2) {
                continue;
            }

            $parsed = $this->parseLine($line, $collections);
            if ($parsed !== null) {
                if ($currentCollection !== null && ! isset($parsed['collection_hint'])) {
                    $parsed['collection_hint'] = $currentCollection;
                    $parsed['collection_confidence'] = $currentCollectionConfidence;
                }
                $candidates[] = $parsed;
            }
        }

        if ($candidates === []) {
            $candidates = $this->parseLoosePairs($text);
        }

        return $candidates;
    }

    /**
     * @param  array<string, string>  $collections
     * @return array{name: string, confidence: string, warning: string}|null
     */
    protected function matchCollectionHeader(string $line, array $collections): ?array
    {
        if ($collections === []) {
            return null;
        }

        if (preg_match('/\d/u', $line) || preg_match('/(?:—|-|–|:|=)/u', $line)) {
            return null;
        }

        return $this->resolveCollectionName($line, $collections);
    }

    /**
     * Fuzzy-resolve a typed collection token to a known catalog name.
     *
     * @param  array<string, string>  $collections
     * @return array{name: string, confidence: string, warning: string}|null
     */
    protected function resolveCollectionName(string $token, array $collections): ?array
    {
        $normalized = $this->codes->normalizeCode($token);
        if ($normalized === '') {
            return null;
        }

        if (isset($collections[$normalized])) {
            return [
                'name' => $collections[$normalized],
                'confidence' => 'high',
                'warning' => '',
            ];
        }

        $bestName = null;
        $bestScore = 0.0;

        foreach ($collections as $norm => $display) {
            if ($norm === '') {
                continue;
            }

            similar_text($normalized, $norm, $pct);
            $maxLen = max(strlen($normalized), strlen($norm));
            $lev = $maxLen > 0
                ? (1 - (levenshtein($normalized, $norm) / $maxLen)) * 100
                : 0.0;
            // Prefix / contained typos (agnona vs agnonna)
            $containBoost = 0.0;
            if (str_contains($norm, $normalized) || str_contains($normalized, $norm)) {
                $containBoost = 12.0;
            }

            $score = max($pct, $lev) + $containBoost;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestName = $display;
            }
        }

        if ($bestName === null) {
            return null;
        }

        if ($bestScore >= 92) {
            return [
                'name' => $bestName,
                'confidence' => 'high',
                'warning' => '',
            ];
        }

        if ($bestScore >= 78) {
            return [
                'name' => $bestName,
                'confidence' => 'medium',
                'warning' => sprintf('Soft collection match: “%s” → %s', $token, $bestName),
            ];
        }

        if ($bestScore >= 62) {
            return [
                'name' => $bestName,
                'confidence' => 'low',
                'warning' => sprintf('Weak collection match: “%s” → %s — confirm', $token, $bestName),
            ];
        }

        return null;
    }

    protected function unitRegex(): string
    {
        return 'vnt|pcs?|pc|rolls?|rit(?:imas|imai|us)?|m|lm|meters?';
    }

    /**
     * @return array{color: string, amount: float, unit: string}|null
     */
    protected function parseColorQtyLine(string $line): ?array
    {
        $unit = $this->unitRegex();

        if (preg_match(
            '/^(?P<color>.+?)\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
            $line,
            $m,
        )) {
            $color = trim($m['color']);
            if ($color !== '') {
                return [
                    'color' => $color,
                    'amount' => (float) str_replace(',', '.', $m['qty']),
                    'unit' => strtolower($m['unit'] ?? ''),
                ];
            }
        }

        if (preg_match(
            '/^(?P<color>\d{1,8}|[A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*){0,4})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
            $line,
            $m,
        )) {
            return [
                'color' => trim($m['color']),
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $collections
     * @return array{code: string, amount: float, unit: string, collection_hint?: string, collection_confidence?: string}|null
     */
    protected function parseLine(string $line, array $collections = []): ?array
    {
        $unit = $this->unitRegex();

        if ($collections !== []) {
            $normalizedLine = $this->codes->normalizeCode($line);
            $sorted = $collections;
            uksort($sorted, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            foreach ($sorted as $normalizedCollection => $displayName) {
                if ($normalizedCollection === '' || ! str_starts_with($normalizedLine, $normalizedCollection)) {
                    continue;
                }

                $prefixPattern = preg_quote($displayName, '/');
                if (preg_match(
                    '/^'.$prefixPattern.'\s+(?P<color>.+?)\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
                    $line,
                    $m,
                )) {
                    return [
                        'code' => $displayName.' '.trim($m['color']),
                        'amount' => (float) str_replace(',', '.', $m['qty']),
                        'unit' => strtolower($m['unit'] ?? ''),
                        'collection_hint' => $displayName,
                        'collection_confidence' => 'high',
                    ];
                }

                if (preg_match(
                    '/^'.$prefixPattern.'\s+(?P<color>\d{1,8}|[A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*){0,4})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
                    $line,
                    $m,
                )) {
                    return [
                        'code' => $displayName.' '.trim($m['color']),
                        'amount' => (float) str_replace(',', '.', $m['qty']),
                        'unit' => strtolower($m['unit'] ?? ''),
                        'collection_hint' => $displayName,
                        'collection_confidence' => 'high',
                    ];
                }
            }

            // Fuzzy collection prefix on one line: "agnana 101 - 10"
            if (preg_match(
                '/^(?P<collection>[A-Za-z][A-Za-z0-9\/\-]*(?:\s+[A-Za-z][A-Za-z0-9\/\-]*){0,5})\s+(?P<rest>.+)$/u',
                $line,
                $parts,
            )) {
                $resolved = $this->resolveCollectionName($parts['collection'], $collections);
                if ($resolved !== null) {
                    $restLine = $resolved['name'].' '.$parts['rest'];
                    $normalizedRest = $this->codes->normalizeCode($restLine);
                    if (str_starts_with($normalizedRest, $this->codes->normalizeCode($resolved['name']))) {
                        $colorQty = $this->parseColorQtyLine($parts['rest']);
                        if ($colorQty !== null) {
                            return [
                                'code' => $resolved['name'].' '.$colorQty['color'],
                                'amount' => $colorQty['amount'],
                                'unit' => $colorQty['unit'],
                                'collection_hint' => $resolved['name'],
                                'collection_confidence' => $resolved['confidence'],
                            ];
                        }
                    }
                }
            }
        }

        if (preg_match(
            '/^(?P<collection>[A-Za-z][A-Za-z0-9\/\-]*(?:\s+[A-Za-z][A-Za-z0-9\/\-]*){0,5})\s+(?P<color>\d{1,8}|[A-Za-z]{0,3}\d{1,8})\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
            $line,
            $m,
        )) {
            $resolved = $collections !== []
                ? ($this->resolveCollectionName($m['collection'], $collections) ?? [
                    'name' => trim($m['collection']),
                    'confidence' => 'low',
                    'warning' => '',
                ])
                : [
                    'name' => trim($m['collection']),
                    'confidence' => 'high',
                    'warning' => '',
                ];

            return [
                'code' => $resolved['name'].' '.trim($m['color']),
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
                'collection_hint' => $resolved['name'],
                'collection_confidence' => $resolved['confidence'],
            ];
        }

        if (preg_match(
            '/^(?P<collection>[A-Za-z][A-Za-z0-9\/\-]*(?:\s+[A-Za-z][A-Za-z0-9\/\-]*){0,5})\s+(?P<color>\d{1,8})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*$/iu',
            $line,
            $m,
        )) {
            $resolved = $collections !== []
                ? ($this->resolveCollectionName($m['collection'], $collections) ?? [
                    'name' => trim($m['collection']),
                    'confidence' => 'low',
                    'warning' => '',
                ])
                : [
                    'name' => trim($m['collection']),
                    'confidence' => 'high',
                    'warning' => '',
                ];

            return [
                'code' => $resolved['name'].' '.trim($m['color']),
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
                'collection_hint' => $resolved['name'],
                'collection_confidence' => $resolved['confidence'],
            ];
        }

        // Full SKU + qty (optional unit, including rit)
        if (preg_match(
            '/(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})\s*(?:—|-|–|:|=|,)?\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\b/iu',
            $line,
            $m,
        )) {
            return [
                'code' => $m['code'],
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        if (preg_match(
            '/(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>'.$unit.')?\s*[x×*]\s*(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})/iu',
            $line,
            $m,
        )) {
            return [
                'code' => $m['code'],
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        // Only a product-like code → 1 roll
        if (preg_match('/^(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})$/u', $line, $m)) {
            return [
                'code' => $m['code'],
                'amount' => 1.0,
                'unit' => '',
            ];
        }

        // Only a number (color code) — match later if unique
        if (preg_match('/^(?P<code>\d{1,8})$/u', $line, $m)) {
            return [
                'code' => $m['code'],
                'amount' => 1.0,
                'unit' => '',
            ];
        }

        return null;
    }

    /**
     * @return list<array{code: string, amount: float, unit: string}>
     */
    protected function parseLoosePairs(string $text): array
    {
        $candidates = [];
        $unit = $this->unitRegex();

        if (preg_match_all(
            '/\b(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})\b(?:\s*(?:—|-|–|:|=)?\s*(?P<qty>\d+(?:[.,]\d+)?)?\s*(?P<unit>'.$unit.')?)?/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                $code = $m['code'];
                $normalized = $this->codes->normalizeCode($code);
                if (strlen($normalized) < 8) {
                    continue;
                }

                $candidates[] = [
                    'code' => $code,
                    'amount' => isset($m['qty']) && $m['qty'] !== ''
                        ? (float) str_replace(',', '.', $m['qty'])
                        : 1.0,
                    'unit' => strtolower($m['unit'] ?? ''),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Resolve unit + convert meters → rolls.
     * Default: rolls. Explicit rit/ritimas/vnt/pcs = rolls.
     * Explicit m/lm = meters. Bare qty ≥ 25 = meters.
     */
    protected function normalizeAmount(float $amount, string $unit, ?Product $product): int
    {
        $resolved = $this->resolveUnit($unit, $amount);
        $size = $product?->sizeMeters() ?: self::DEFAULT_ROLL_METERS;

        if ($resolved === 'm') {
            return max(1, (int) round($amount / $size));
        }

        return max(0, (int) round($amount));
    }

    protected function resolveUnit(string $unit, float $amount): string
    {
        $unit = strtolower(trim($unit));

        if (in_array($unit, ['m', 'lm', 'meter', 'meters'], true)) {
            return 'm';
        }

        if ($unit !== '' && preg_match('/^(vnt|pcs?|pc|rolls?|rit)/u', $unit)) {
            return 'rolls';
        }

        if ($unit === '' && $amount >= self::METERS_QTY_THRESHOLD) {
            return 'm';
        }

        return 'rolls';
    }

    protected function productLabel(Product $product): string
    {
        $product->loadMissing('color.collection');
        $collection = $product->color?->collection?->name;
        $color = trim(implode(' ', array_filter([
            $product->color?->color_code,
            $product->color?->color_name,
        ])));

        $size = $product->sizeMeters();

        return trim(implode(' · ', array_filter([
            $collection,
            $color !== '' ? $color : null,
            $size ? $size.' m' : null,
            $product->product_code,
        ])));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function detailLine(array $line): string
    {
        if (! ($line['matched'] ?? false)) {
            return (string) ($line['warning'] ?: 'Not matched');
        }

        $amount = (int) ($line['amount'] ?? 0);
        $size = $line['size_meters'] ?? null;
        $stock = $line['stock'];
        $parts = [];

        if ($size) {
            $meters = $amount * (float) $size;
            $parts[] = sprintf(
                '%d rit × %s m = %s m · stock %s',
                $amount,
                rtrim(rtrim(number_format((float) $size, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.'),
                $stock === null ? '—' : $stock,
            );
        } else {
            $parts[] = sprintf('%d rit · stock %s', $amount, $stock === null ? '—' : $stock);
        }

        if (($line['warning'] ?? '') !== '') {
            $parts[] = (string) $line['warning'];
        }

        return implode(' · ', $parts);
    }

    /**
     * @return Collection<int, Product>
     */
    protected function productsForSupplier(?int $supplierId): Collection
    {
        $query = Product::query()
            ->artificialLeather()
            ->with(['color.collection']);

        if ($supplierId !== null) {
            $ids = CargoResource::productIdsForSupplier($supplierId);

            if ($ids === []) {
                return collect();
            }

            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<string, string> normalized => display name
     */
    protected function knownCollections(Collection $products): array
    {
        $collections = [];

        foreach ($products as $product) {
            $name = trim((string) ($product->color?->collection?->name ?? ''));
            if ($name === '') {
                continue;
            }

            $collections[$this->codes->normalizeCode($name)] = $name;
        }

        return $collections;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{
     *     codes: array<string, Product>,
     *     suffixes: array<string, Product>,
     *     prefixes: list<array{prefix: string, size: string, product: Product}>,
     *     collectionColors: array<string, Product>,
     *     colorCodes: array<string, list<Product>>
     * }
     */
    protected function buildIndex(Collection $products): array
    {
        $codes = [];
        $suffixes = [];
        $prefixes = [];
        $collectionColors = [];
        $colorCodes = [];

        foreach ($products as $product) {
            foreach ([$product->product_code, $product->alternative_code, $product->dsv_code] as $code) {
                $normalized = $this->codes->normalizeCode((string) $code);
                if ($normalized !== '' && ! isset($codes[$normalized])) {
                    $codes[$normalized] = $product;
                }
            }

            $collection = $this->codes->normalizeCode((string) ($product->color?->collection?->name ?? ''));
            $colorCode = $this->codes->normalizeCode((string) ($product->color?->color_code ?? ''));
            $colorName = $this->codes->normalizeCode((string) ($product->color?->color_name ?? ''));
            $size = $this->codes->normalizeCode((string) $product->name);

            $reconstructed = $collection.$colorCode.$colorName.$size;
            if ($reconstructed !== '' && ! isset($codes[$reconstructed])) {
                $codes[$reconstructed] = $product;
            }

            if ($colorCode !== '') {
                $colorCodes[$colorCode] ??= [];
                $colorCodes[$colorCode][] = $product;
            }

            foreach (array_filter([$colorCode, $colorName]) as $colorKey) {
                if ($collection === '' || $colorKey === '') {
                    continue;
                }

                $collectionColorKey = $collection.$colorKey;
                if (
                    ! isset($collectionColors[$collectionColorKey])
                    || (int) $product->current_amount > (int) $collectionColors[$collectionColorKey]->current_amount
                ) {
                    $collectionColors[$collectionColorKey] = $product;
                }

                $codes[$collectionColorKey] = $collectionColors[$collectionColorKey];
            }

            if ($colorCode !== '' && $colorName !== '' && $size !== '') {
                $suffix = $colorCode.$colorName.$size;
                if (! isset($suffixes[$suffix])) {
                    $suffixes[$suffix] = $product;
                }
            }

            if ($collection !== '' && $colorCode !== '' && $size !== '') {
                $prefixes[] = [
                    'prefix' => $collection.$colorCode,
                    'size' => $size,
                    'product' => $product,
                ];
            }

            if ($product->product_code) {
                $internal = $this->codes->normalizeCode((string) $product->product_code);
                if ($internal !== '' && ! isset($codes[$internal])) {
                    $codes[$internal] = $product;
                }
            }
        }

        usort(
            $prefixes,
            fn (array $left, array $right): int => strlen($right['prefix']) <=> strlen($left['prefix']),
        );

        return compact('codes', 'suffixes', 'prefixes', 'collectionColors', 'colorCodes');
    }

    /**
     * @param  array{
     *     codes: array<string, Product>,
     *     suffixes: array<string, Product>,
     *     prefixes: list<array{prefix: string, size: string, product: Product}>,
     *     collectionColors: array<string, Product>,
     *     colorCodes: array<string, list<Product>>
     * }  $index
     * @param  Collection<int, Product>  $products
     * @param  array<string, string>  $collections
     * @return array{product: ?Product, confidence: string, warning: string}
     */
    protected function matchProductDetailed(
        string $code,
        array $index,
        Collection $products,
        array $collections,
        string $collectionHint = '',
        string $collectionConfidence = 'high',
    ): array {
        $normalized = $this->codes->normalizeCode($code);
        $warning = '';
        $confidence = $collectionConfidence !== '' ? $collectionConfidence : 'high';

        if ($normalized !== '' && isset($index['codes'][$normalized])) {
            return [
                'product' => $index['codes'][$normalized],
                'confidence' => $this->worseConfidence('high', $confidence),
                'warning' => $confidence === 'high' ? '' : $this->softCollectionWarning($collectionHint, $confidence),
            ];
        }

        if ($normalized !== '' && isset($index['collectionColors'][$normalized])) {
            return [
                'product' => $index['collectionColors'][$normalized],
                'confidence' => $this->worseConfidence('high', $confidence),
                'warning' => $confidence === 'high' ? '' : $this->softCollectionWarning($collectionHint, $confidence),
            ];
        }

        // "Agnona 101" / fuzzy collection + color token
        $parts = preg_split('/\s+/u', trim($code)) ?: [];
        if (count($parts) >= 2) {
            $colorToken = (string) array_pop($parts);
            $colToken = implode(' ', $parts);
            $resolved = $this->resolveCollectionName($colToken, $collections);
            if ($resolved !== null) {
                $keyAlt = $this->codes->normalizeCode($resolved['name']).$this->codes->normalizeCode($colorToken);
                $key = $this->codes->normalizeCode($resolved['name'].$colorToken);
                $product = $index['codes'][$key]
                    ?? $index['codes'][$keyAlt]
                    ?? $index['collectionColors'][$keyAlt]
                    ?? null;
                if ($product !== null) {
                    return [
                        'product' => $product,
                        'confidence' => $this->worseConfidence($resolved['confidence'], $confidence),
                        'warning' => $resolved['warning'],
                    ];
                }
            }
        }

        foreach ($index['suffixes'] as $suffix => $product) {
            if ($suffix !== '' && str_ends_with($normalized, $suffix)) {
                return [
                    'product' => $product,
                    'confidence' => $this->worseConfidence('medium', $confidence),
                    'warning' => 'Matched by code suffix — confirm',
                ];
            }
        }

        foreach ($index['prefixes'] as $candidate) {
            if (
                str_starts_with($normalized, $candidate['prefix'])
                && str_ends_with($normalized, $candidate['size'])
            ) {
                return [
                    'product' => $candidate['product'],
                    'confidence' => $this->worseConfidence('medium', $confidence),
                    'warning' => 'Matched by collection+color prefix — confirm',
                ];
            }
        }

        // Bare color code only (or unique number) — low confidence unless unique
        if (preg_match('/^\d{1,8}$/u', $normalized) && isset($index['colorCodes'][$normalized])) {
            $hits = $index['colorCodes'][$normalized];
            $uniqueByColor = [];
            foreach ($hits as $hit) {
                $cid = (int) ($hit->color_id ?? 0);
                if (! isset($uniqueByColor[$cid])
                    || (int) $hit->current_amount > (int) $uniqueByColor[$cid]->current_amount
                ) {
                    $uniqueByColor[$cid] = $hit;
                }
            }
            $unique = array_values($uniqueByColor);
            if (count($unique) === 1) {
                return [
                    'product' => $unique[0],
                    'confidence' => 'low',
                    'warning' => 'Matched by color code only — confirm collection/product',
                ];
            }

            if ($collectionHint !== '') {
                $hintNorm = $this->codes->normalizeCode($collectionHint);
                foreach ($unique as $hit) {
                    $collectionName = '';
                    $color = $hit->color;
                    if ($color instanceof Color) {
                        $collection = $color->collection;
                        if ($collection instanceof ProductCollection) {
                            $collectionName = (string) $collection->name;
                        }
                    }

                    $col = $this->codes->normalizeCode($collectionName);
                    if ($col === $hintNorm) {
                        return [
                            'product' => $hit,
                            'confidence' => $this->worseConfidence('high', $confidence),
                            'warning' => $confidence === 'high' ? '' : $this->softCollectionWarning($collectionHint, $confidence),
                        ];
                    }
                }
            }
        }

        return [
            'product' => null,
            'confidence' => 'none',
            'warning' => 'Not matched',
        ];
    }

    protected function softCollectionWarning(string $hint, string $confidence): string
    {
        if ($hint === '' || $confidence === 'high') {
            return '';
        }

        return $confidence === 'low'
            ? 'Weak collection match — confirm'
            : 'Soft collection match — confirm';
    }

    protected function worseConfidence(string $a, string $b): string
    {
        $rank = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

        return ($rank[$a] ?? 3) <= ($rank[$b] ?? 3) ? $a : $b;
    }
}
