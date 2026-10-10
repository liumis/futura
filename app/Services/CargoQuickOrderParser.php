<?php

namespace App\Services;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Product;
use Illuminate\Support\Collection;

class CargoQuickOrderParser
{
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
     *     detail: string
     * }>
     */
    public function parse(string $text, ?int $supplierId = null): array
    {
        $products = $this->productsForSupplier($supplierId);
        $index = $this->buildIndex($products);
        $candidates = $this->extractCandidates($text, $this->knownCollections($products));

        if ($candidates === []) {
            return [];
        }

        $merged = [];

        foreach ($candidates as $candidate) {
            $product = $this->matchProduct($candidate['code'], $index, $products);
            $amount = $this->normalizeAmount($candidate['amount'], $candidate['unit'], $product);

            if ($amount <= 0) {
                continue;
            }

            $key = $product?->getKey() ?? ('unmatched:'.$this->codes->normalizeCode($candidate['code']));

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
                ];
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

            $rows[] = sprintf(
                '%s — %d %s',
                (string) ($line['code'] ?? ''),
                (int) $line['amount'],
                (string) ($line['unit'] ?? 'vnt'),
            );
        }

        if (count($rows) === 1) {
            $rows[] = '(no matched items)';
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string, string>  $collections  normalized => display name
     * @return list<array{code: string, amount: float, unit: string}>
     */
    protected function extractCandidates(string $text, array $collections = []): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split("/\n+/", $text) ?: [];
        $candidates = [];
        $currentCollection = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip obvious headers
            if (preg_match('/^(užsakymas|order|quantity|kiekis|product|preke)\b/iu', $line)) {
                continue;
            }

            $headerCollection = $this->matchCollectionHeader($line, $collections);
            if ($headerCollection !== null) {
                $currentCollection = $headerCollection;

                continue;
            }

            if ($currentCollection !== null) {
                $underCollection = $this->parseColorQtyLine($line);
                if ($underCollection !== null) {
                    $candidates[] = [
                        'code' => $currentCollection.' '.$underCollection['color'],
                        'amount' => $underCollection['amount'],
                        'unit' => $underCollection['unit'],
                    ];

                    continue;
                }
            }

            if (mb_strlen($line) < 4) {
                continue;
            }

            $parsed = $this->parseLine($line, $collections);
            if ($parsed !== null) {
                $candidates[] = $parsed;
            }
        }

        // Whole-text fallback: CODE ... qty pairs
        if ($candidates === []) {
            $candidates = $this->parseLoosePairs($text);
        }

        return $candidates;
    }

    /**
     * @param  array<string, string>  $collections
     */
    protected function matchCollectionHeader(string $line, array $collections): ?string
    {
        if ($collections === []) {
            return null;
        }

        // Pure collection header: no quantity / dash pair
        if (preg_match('/\d/u', $line) || preg_match('/(?:—|-|–|:|=)/u', $line)) {
            return null;
        }

        $normalized = $this->codes->normalizeCode($line);

        return $collections[$normalized] ?? null;
    }

    /**
     * @return array{color: string, amount: float, unit: string}|null
     */
    protected function parseColorQtyLine(string $line): ?array
    {
        // "101 - 10", "Arctic — 20 vnt", "Arctic White - 5"
        if (preg_match(
            '/^(?P<color>.+?)\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
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

        // "101 10", "Arctic 20"
        if (preg_match(
            '/^(?P<color>\d{1,8}|[A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*){0,4})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
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
     * @return array{code: string, amount: float, unit: string}|null
     */
    protected function parseLine(string $line, array $collections = []): ?array
    {
        // Prefer longest known collection prefix + color + qty: "agnona Arctic - 10"
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
                    '/^'.$prefixPattern.'\s+(?P<color>.+?)\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
                    $line,
                    $m,
                )) {
                    return [
                        'code' => $displayName.' '.trim($m['color']),
                        'amount' => (float) str_replace(',', '.', $m['qty']),
                        'unit' => strtolower($m['unit'] ?? ''),
                    ];
                }

                if (preg_match(
                    '/^'.$prefixPattern.'\s+(?P<color>\d{1,8}|[A-Za-z][A-Za-z0-9]*(?:\s+[A-Za-z][A-Za-z0-9]*){0,4})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
                    $line,
                    $m,
                )) {
                    return [
                        'code' => $displayName.' '.trim($m['color']),
                        'amount' => (float) str_replace(',', '.', $m['qty']),
                        'unit' => strtolower($m['unit'] ?? ''),
                    ];
                }
            }
        }

        // Collection + color code + qty: "agnona 101 - 10", "Agnona 102 — 3 vnt"
        if (preg_match(
            '/^(?P<collection>[A-Za-z][A-Za-z0-9\/\-]*(?:\s+[A-Za-z][A-Za-z0-9\/\-]*){0,5})\s+(?P<color>\d{1,8}|[A-Za-z]{0,3}\d{1,8})\s*(?:—|-|–|:|=)\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
            $line,
            $m,
        )) {
            return [
                'code' => trim($m['collection']).' '.trim($m['color']),
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        // Collection + color code + qty without separator: "agnona 101 10"
        if (preg_match(
            '/^(?P<collection>[A-Za-z][A-Za-z0-9\/\-]*(?:\s+[A-Za-z][A-Za-z0-9\/\-]*){0,5})\s+(?P<color>\d{1,8})\s+(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\s*$/iu',
            $line,
            $m,
        )) {
            return [
                'code' => trim($m['collection']).' '.trim($m['color']),
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        // AGNONA102ARCTIC30 — 3 vnt
        if (preg_match(
            '/(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})\s*(?:—|-|–|:|=|,)?\s*(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm|meters?)?\b/iu',
            $line,
            $m,
        )) {
            return [
                'code' => $m['code'],
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        // 3 x AGNONA102ARCTIC30
        if (preg_match(
            '/(?P<qty>\d+(?:[.,]\d+)?)\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm)?\s*[x×*]\s*(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})/iu',
            $line,
            $m,
        )) {
            return [
                'code' => $m['code'],
                'amount' => (float) str_replace(',', '.', $m['qty']),
                'unit' => strtolower($m['unit'] ?? ''),
            ];
        }

        // Only a product-like code on the line → amount 1
        if (preg_match('/^(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})$/u', $line, $m)) {
            return [
                'code' => $m['code'],
                'amount' => 1,
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

        if (preg_match_all(
            '/\b(?P<code>[A-Za-z][A-Za-z0-9\-\/]{5,})\b(?:\s*(?:—|-|–|:|=)?\s*(?P<qty>\d+(?:[.,]\d+)?)?\s*(?P<unit>vnt|pcs?|pc|rolls?|m|lm)?)?/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                $code = $m['code'];
                $normalized = $this->codes->normalizeCode($code);
                // Ignore short/generic tokens
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

    protected function normalizeAmount(float $amount, string $unit, ?Product $product): int
    {
        $unit = strtolower(trim($unit));
        $size = $product?->sizeMeters();

        if ($size && in_array($unit, ['m', 'lm', 'meter', 'meters'], true)) {
            return max(1, (int) round($amount / $size));
        }

        return max(0, (int) round($amount));
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
            return 'Not matched';
        }

        $amount = (int) ($line['amount'] ?? 0);
        $size = $line['size_meters'] ?? null;
        $stock = $line['stock'];

        if ($size) {
            $meters = $amount * (float) $size;

            return sprintf(
                '%d vnt × %s m = %s m · stock %s',
                $amount,
                rtrim(rtrim(number_format((float) $size, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.'),
                $stock === null ? '—' : $stock,
            );
        }

        return sprintf('%d vnt · stock %s', $amount, $stock === null ? '—' : $stock);
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
     *     collectionColors: array<string, Product>
     * }
     */
    protected function buildIndex(Collection $products): array
    {
        $codes = [];
        $suffixes = [];
        $prefixes = [];
        $collectionColors = [];

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

        return compact('codes', 'suffixes', 'prefixes', 'collectionColors');
    }

    /**
     * @param  array{
     *     codes: array<string, Product>,
     *     suffixes: array<string, Product>,
     *     prefixes: list<array{prefix: string, size: string, product: Product}>,
     *     collectionColors: array<string, Product>
     * }  $index
     * @param  Collection<int, Product>  $products
     */
    protected function matchProduct(string $code, array $index, Collection $products): ?Product
    {
        $normalized = $this->codes->normalizeCode($code);

        if ($normalized !== '' && isset($index['codes'][$normalized])) {
            return $index['codes'][$normalized];
        }

        if ($normalized !== '' && isset($index['collectionColors'][$normalized])) {
            return $index['collectionColors'][$normalized];
        }

        foreach ($index['suffixes'] as $suffix => $product) {
            if ($suffix !== '' && str_ends_with($normalized, $suffix)) {
                return $product;
            }
        }

        foreach ($index['prefixes'] as $candidate) {
            if (
                str_starts_with($normalized, $candidate['prefix'])
                && str_ends_with($normalized, $candidate['size'])
            ) {
                return $candidate['product'];
            }
        }

        return null;
    }
}
