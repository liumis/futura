<?php

namespace App\Services;

use App\Enums\ActivityLogEvent;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\ProductType;
use App\Models\StockManualUpdate;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExternalStockImporter
{
    public function __construct(
        protected ExternalStockExcelParser $parser = new ExternalStockExcelParser,
        protected ExternalStockTitleParser $titles = new ExternalStockTitleParser,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     unmatched: int,
     *     unmatched_codes: list<string>,
     *     reservations_updated: int,
     *     reservations_unchanged: int,
     *     file_name: string
     * }
     */
    public function import(string $path, ?string $fileName = null, ?int $userId = null): array
    {
        $fileName = $fileName ?: basename($path);
        $rows = $this->parser->parse($path);
        $stockRows = [];
        $reservationRows = [];

        foreach ($rows as $row) {
            if (($row['section'] ?? 'stock') === 'reservation') {
                $reservationRows[] = $row;
            } else {
                $stockRows[] = $row;
            }
        }

        if ($stockRows === [] && $reservationRows === []) {
            throw new RuntimeException('No product rows with quantities were found in the Excel file.');
        }

        $userId ??= auth()->id();
        $products = Product::query()->with(['productType', 'color.collection'])->get();
        $index = $this->buildIndex($products);
        $leatherType = $this->leatherType();
        $catalogType = $this->catalogType();

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $unmatchedCodes = [];
        $reservationsUpdated = 0;
        $reservationsUnchanged = 0;
        $collectionCostMeters = [];
        $collectionCostValue = [];

        DB::transaction(function () use (
            $stockRows,
            $reservationRows,
            &$index,
            &$products,
            $leatherType,
            $catalogType,
            $userId,
            &$created,
            &$updated,
            &$unchanged,
            &$unmatchedCodes,
            &$reservationsUpdated,
            &$reservationsUnchanged,
            &$collectionCostMeters,
            &$collectionCostValue,
        ): void {
            $grouped = $this->groupImportRows($stockRows, $unmatchedCodes);

            foreach ($grouped['leather'] as $group) {
                $createdBefore = $created;
                $product = $this->syncLeatherProduct($group, $index, $products, $leatherType, $created);
                $price = $group['has_price'] && $group['quantity'] > 0
                    ? round($group['value'] / $group['quantity'], 2)
                    : null;

                $this->applyProductSync(
                    $product,
                    $group['quantity'],
                    $group['unit'],
                    $price,
                    $this->preferredExcelCode($group['codes'], $group['parsed']['collection']),
                    $userId,
                    $updated,
                    $unchanged,
                    countStockChange: $created === $createdBefore,
                );

                $collectionId = $product->color?->collection_id;
                if ($collectionId && $group['has_price'] && $group['quantity'] > 0) {
                    $collectionCostMeters[$collectionId] = ($collectionCostMeters[$collectionId] ?? 0) + $group['quantity'];
                    $collectionCostValue[$collectionId] = ($collectionCostValue[$collectionId] ?? 0) + $group['value'];
                }
            }

            foreach ($grouped['catalog'] as $row) {
                $createdBefore = $created;
                $product = $this->matchProduct($row['code'], $row['name'] ?? null, $index, $products);

                if ($product !== null && ! $product->isCatalog()) {
                    $this->applyProductSync(
                        $product,
                        $row['quantity'],
                        $row['unit'] ?? null,
                        $row['price'] ?? null,
                        $row['code'],
                        $userId,
                        $updated,
                        $unchanged,
                        countStockChange: true,
                    );

                    continue;
                }

                $product = $this->syncCatalogProduct($row, $product, $index, $products, $catalogType, $created);

                $this->applyProductSync(
                    $product,
                    $row['quantity'],
                    $row['unit'] ?? null,
                    $row['price'] ?? null,
                    $row['code'],
                    $userId,
                    $updated,
                    $unchanged,
                    countStockChange: $created === $createdBefore,
                );
            }

            $this->syncReservationRows(
                $reservationRows,
                $index,
                $products,
                $leatherType,
                $created,
                $unmatchedCodes,
                $reservationsUpdated,
                $reservationsUnchanged,
            );

            foreach ($collectionCostMeters as $collectionId => $meters) {
                if ($meters <= 0) {
                    continue;
                }

                $cost = round($collectionCostValue[$collectionId] / $meters, 2);
                $collection = Collection::query()->find($collectionId);
                if ($collection === null || abs((float) $collection->cost - $cost) < 0.001) {
                    continue;
                }

                Collection::withoutEvents(function () use ($collection, $cost): void {
                    $collection->update(['cost' => $cost]);
                });
            }
        });

        $result = [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'unmatched' => count($unmatchedCodes),
            'unmatched_codes' => array_values(array_slice($unmatchedCodes, 0, 50)),
            'reservations_updated' => $reservationsUpdated,
            'reservations_unchanged' => $reservationsUnchanged,
            'file_name' => $fileName,
        ];

        ActivityLogger::log(
            ActivityLogEvent::StockImportedFromExcel,
            sprintf(
                'Stock updated from Excel: %s (%s created, %s updated, %s unchanged, %s unmatched, %s reservations)',
                $fileName,
                number_format($created),
                number_format($updated),
                number_format($unchanged),
                number_format(count($unmatchedCodes)),
                number_format($reservationsUpdated),
            ),
            null,
            $result,
            userId: $userId,
            allowWithoutUser: true,
        );

        return $result;
    }

    /**
     * @param  list<array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int, section?: string}>  $rows
     * @param  list<string>  $unmatchedCodes
     * @return array{
     *     leather: array<string, array{
     *         parsed: array{collection: string, color_code: string, color_name: string, size: string},
     *         quantity: float,
     *         value: float,
     *         has_price: bool,
     *         codes: list<string>,
     *         unit: ?string
     *     }>,
     *     catalog: list<array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int, section?: string}>
     * }
     */
    private function groupImportRows(array $rows, array &$unmatchedCodes): array
    {
        $leatherGroups = [];
        $catalogRows = [];

        foreach ($rows as $row) {
            if ($this->titles->isLeather($row['name'] ?? null)) {
                $parsed = $this->titles->parseLeather($row['name'] ?? null);
                if ($parsed === null) {
                    $unmatchedCodes[] = $row['code'];

                    continue;
                }

                $key = implode('|', [
                    strtoupper($parsed['collection']),
                    $parsed['color_code'],
                    strtoupper($parsed['color_name']),
                    $parsed['size'],
                ]);

                if (! isset($leatherGroups[$key])) {
                    $leatherGroups[$key] = [
                        'parsed' => $parsed,
                        'quantity' => 0.0,
                        'value' => 0.0,
                        'has_price' => false,
                        'codes' => [],
                        'unit' => $row['unit'] ?? null,
                    ];
                }

                $leatherGroups[$key]['quantity'] += $row['quantity'];
                $leatherGroups[$key]['codes'][] = $row['code'];
                if (($row['price'] ?? null) !== null) {
                    $leatherGroups[$key]['value'] += $row['quantity'] * (float) $row['price'];
                    $leatherGroups[$key]['has_price'] = true;
                }
                if (filled($row['unit'] ?? null)) {
                    $leatherGroups[$key]['unit'] = $row['unit'];
                }

                continue;
            }

            $catalogRows[] = $row;
        }

        return [
            'leather' => $leatherGroups,
            'catalog' => $catalogRows,
        ];
    }

    /**
     * @param  list<array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int, section?: string}>  $rows
     * @param  array{codes: array<string, Product>, suffixes: array<string, Product>, prefixes: list<array{prefix: string, size: string, product: Product}>}  $index
     * @param  EloquentCollection<int, Product>  $products
     * @param  list<string>  $unmatchedCodes
     */
    private function syncReservationRows(
        array $rows,
        array &$index,
        EloquentCollection $products,
        ProductType $leatherType,
        int &$created,
        array &$unmatchedCodes,
        int &$reservationsUpdated,
        int &$reservationsUnchanged,
    ): void {
        if ($rows === []) {
            return;
        }

        $grouped = $this->groupImportRows($rows, $unmatchedCodes);

        foreach ($grouped['leather'] as $group) {
            $product = $this->syncLeatherProduct($group, $index, $products, $leatherType, $created);
            $this->applyReservationQuantity(
                $product,
                $group['quantity'],
                $group['unit'] ?? null,
                $reservationsUpdated,
                $reservationsUnchanged,
            );
        }

        // Reservations are artificial leather only — catalog sample rows are ignored.
    }

    private function applyReservationQuantity(
        Product $product,
        float $quantity,
        ?string $unit,
        int &$reservationsUpdated,
        int &$reservationsUnchanged,
    ): void {
        $amount = $this->quantityToStock($product, $quantity, $unit);
        $reservation = ProductReservation::query()->firstOrNew([
            'product_id' => $product->getKey(),
        ]);
        $oldAmount = (int) ($reservation->quantity ?? 0);

        if ($reservation->exists && $oldAmount === $amount) {
            $reservationsUnchanged++;

            return;
        }

        $reservation->quantity = $amount;
        $reservation->save();
        $reservationsUpdated++;
    }

    /**
     * @param  array{
     *     parsed: array{collection: string, color_code: string, color_name: string, size: string},
     *     quantity: float,
     *     value: float,
     *     has_price: bool,
     *     codes: list<string>,
     *     unit: ?string
     * }  $group
     * @param  array{codes: array<string, Product>, suffixes: array<string, Product>, prefixes: list<array{prefix: string, size: string, product: Product}>}  $index
     * @param  EloquentCollection<int, Product>  $products
     */
    private function syncLeatherProduct(array $group, array &$index, EloquentCollection $products, ProductType $leatherType, int &$created): Product
    {
        $parsed = $group['parsed'];
        $product = null;

        foreach ($group['codes'] as $code) {
            $product = $this->matchProduct($code, null, $index, $products);
            if ($product !== null) {
                break;
            }
        }

        $collection = $this->findOrCreateCollection($parsed['collection']);
        $color = $this->findOrCreateColor($collection, $parsed['color_code'], $parsed['color_name']);
        $size = $parsed['size'];
        $generatedCode = Product::generateProductCode($color, $size);

        if ($product === null) {
            $product = $index['codes'][$this->parser->normalizeCode($generatedCode)] ?? null;
        }

        if ($product === null) {
            $product = $products->first(
                fn (Product $candidate): bool => (int) $candidate->color_id === (int) $color->getKey()
                    && (string) $candidate->name === $size
                    && ! $candidate->isCatalog(),
            );
        }

        if ($product === null) {
            $product = Product::withoutEvents(fn (): Product => Product::query()->create([
                'product_type_id' => $leatherType->getKey(),
                'color_id' => $color->getKey(),
                'name' => $size,
                'product_code' => $generatedCode,
                'alternative_code' => $group['codes'][0] ?? null,
                'current_amount' => 0,
            ]));
            $product->setRelation('productType', $leatherType);
            $product->setRelation('color', $color->loadMissing('collection'));
            $this->rememberProduct($product, $index, $products);
            $created++;
        }

        $preferredCode = $this->preferredExcelCode($group['codes'], $parsed['collection']);
        $attributes = [];

        if ((int) $product->product_type_id !== (int) $leatherType->getKey()) {
            $attributes['product_type_id'] = $leatherType->getKey();
        }
        if ((int) $product->color_id !== (int) $color->getKey()) {
            $attributes['color_id'] = $color->getKey();
        }
        if ((string) $product->name !== $size) {
            $attributes['name'] = $size;
        }
        if ((string) $product->product_code !== $generatedCode && ! $this->productCodeTaken($generatedCode, $product)) {
            $attributes['product_code'] = $generatedCode;
        }
        if ($preferredCode !== null && (string) $product->alternative_code !== $preferredCode) {
            $attributes['alternative_code'] = $preferredCode;
        }

        if ($attributes !== []) {
            Product::withoutEvents(function () use ($product, $attributes): void {
                $product->update($attributes);
            });
            $product->setRelation('productType', $leatherType);
            $product->setRelation('color', $color->loadMissing('collection'));
            $this->rememberProduct($product, $index, $products);
        }

        return $product;
    }

    /**
     * @param  array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int}  $row
     * @param  array{codes: array<string, Product>, suffixes: array<string, Product>, prefixes: list<array{prefix: string, size: string, product: Product}>}  $index
     * @param  EloquentCollection<int, Product>  $products
     */
    private function syncCatalogProduct(
        array $row,
        ?Product $product,
        array &$index,
        EloquentCollection $products,
        ProductType $catalogType,
        int &$created,
    ): Product {
        $parsed = $this->titles->parseCatalog($row['name'] ?? null, $row['code']);
        $collection = $this->findOrCreateCollection($parsed['collection']);
        $color = $this->findOrCreateColor($collection, $parsed['color_code'], $parsed['color_name']);
        $code = $row['code'];

        if ($product === null) {
            $product = Product::withoutEvents(fn (): Product => Product::query()->create([
                'product_type_id' => $catalogType->getKey(),
                'color_id' => $color->getKey(),
                'name' => '-',
                'product_code' => $code,
                'alternative_code' => $code,
                'current_amount' => 0,
            ]));
            $created++;
        }

        $attributes = [];
        if ((int) $product->product_type_id !== (int) $catalogType->getKey()) {
            $attributes['product_type_id'] = $catalogType->getKey();
        }
        if ((int) $product->color_id !== (int) $color->getKey()) {
            $attributes['color_id'] = $color->getKey();
        }
        if ($this->looksLikeCatalogTitle((string) $product->name) || (string) $product->name === $code) {
            $attributes['name'] = '-';
        }

        if ($attributes !== []) {
            Product::withoutEvents(function () use ($product, $attributes): void {
                $product->update($attributes);
            });
        }

        $product->setRelation('productType', $catalogType);
        $product->setRelation('color', $color->loadMissing('collection'));
        $this->rememberProduct($product, $index, $products);

        return $product;
    }

    private function looksLikeCatalogTitle(string $name): bool
    {
        return preg_match('/^katalogas\b/iu', trim($name)) === 1;
    }

    /**
     * @param  list<string>  $codes
     */
    private function preferredExcelCode(array $codes, string $collection): ?string
    {
        $normalizedCollection = $this->parser->normalizeCode($collection);
        foreach ($codes as $code) {
            if (str_starts_with($this->parser->normalizeCode($code), $normalizedCollection)) {
                return $code;
            }
        }

        return $codes[0] ?? null;
    }

    private function applyProductSync(
        Product $product,
        float $quantity,
        ?string $unit,
        ?float $price,
        ?string $excelCode,
        ?int $userId,
        int &$updated,
        int &$unchanged,
        bool $countStockChange = true,
    ): void {
        $attributes = [];

        if ($product->isCatalog()) {
            if (filled($excelCode) && (string) $product->product_code !== $excelCode && ! $this->productCodeTaken($excelCode, $product)) {
                $attributes['product_code'] = $excelCode;
            }
            if (filled($excelCode) && (string) $product->alternative_code !== $excelCode) {
                $attributes['alternative_code'] = $excelCode;
            }
        }

        if ($price !== null) {
            $cost = round(max(0, $price), 2);
            if ((float) round((float) ($product->cost ?? 0), 2) !== $cost) {
                $attributes['cost'] = $cost;
            }
        }

        $newAmount = $this->quantityToStock($product, $quantity, $unit);
        $oldAmount = (int) $product->current_amount;

        if ($oldAmount !== $newAmount) {
            $attributes['current_amount'] = $newAmount;
        }

        if ($attributes !== []) {
            Product::withoutEvents(function () use ($product, $attributes): void {
                $product->update($attributes);
            });
        }

        if ($oldAmount === $newAmount) {
            if ($countStockChange) {
                $unchanged++;
            }

            return;
        }

        StockManualUpdate::query()->create([
            'product_id' => $product->getKey(),
            'user_id' => $userId,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'created_at' => now(),
        ]);

        if ($countStockChange) {
            $updated++;
        }
    }

    private function findOrCreateCollection(string $name): Collection
    {
        $existing = Collection::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Collection::withoutEvents(fn (): Collection => Collection::query()->create([
            'name' => Str::title(mb_strtolower($name)),
            'supplier_id' => Collection::query()->whereNotNull('supplier_id')->value('supplier_id')
                ?? Supplier::query()->orderBy('id')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id')
                ?? Warehouse::query()->orderBy('id')->value('id'),
            'price' => 0,
            'cost' => 0,
        ]));
    }

    private function findOrCreateColor(Collection $collection, string $colorCode, string $colorName): Color
    {
        $color = Color::query()
            ->where('collection_id', $collection->getKey())
            ->where(function ($query) use ($colorCode, $colorName): void {
                $query->whereRaw('UPPER(color_code) = ?', [strtoupper($colorCode)])
                    ->orWhereRaw('UPPER(color_name) = ?', [strtoupper($colorName)]);
            })
            ->first();

        if ($color === null) {
            $color = Color::query()->create([
                'collection_id' => $collection->getKey(),
                'color_code' => $colorCode,
                'color_name' => $colorName,
            ]);
        } else {
            $changes = [];
            if (strcasecmp((string) $color->color_code, $colorCode) !== 0) {
                $changes['color_code'] = $colorCode;
            }
            if (strcasecmp((string) $color->color_name, $colorName) !== 0) {
                $changes['color_name'] = $colorName;
            }
            if ($changes !== []) {
                $color->update($changes);
            }
        }

        $color->setRelation('collection', $collection);

        return $color;
    }

    /**
     * @param  array{codes: array<string, Product>, suffixes: array<string, Product>, prefixes: list<array{prefix: string, size: string, product: Product}>}  $index
     * @param  EloquentCollection<int, Product>  $products
     */
    private function rememberProduct(Product $product, array &$index, EloquentCollection $products): void
    {
        if (! $products->contains(fn (Product $existing): bool => (int) $existing->getKey() === (int) $product->getKey())) {
            $products->push($product);
        }

        foreach ([$product->product_code, $product->alternative_code, $product->dsv_code] as $code) {
            $normalized = $this->parser->normalizeCode((string) $code);
            if ($normalized !== '') {
                $index['codes'][$normalized] = $product;
            }
        }
    }

    private function productCodeTaken(string $code, Product $ignore): bool
    {
        return Product::query()
            ->where('product_code', $code)
            ->whereKeyNot($ignore->getKey())
            ->exists();
    }

    private function leatherType(): ProductType
    {
        return ProductType::query()->firstOrCreate(
            ['key' => ProductType::KEY_ARTIFICIAL_LEATHER],
            ['name' => 'Artificial leather', 'requires_color' => true],
        );
    }

    private function catalogType(): ProductType
    {
        return ProductType::query()->firstOrCreate(
            ['key' => ProductType::KEY_CATALOG],
            ['name' => 'Catalog', 'requires_color' => false],
        );
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @return array{
     *     codes: array<string, Product>,
     *     suffixes: array<string, Product>,
     *     prefixes: list<array{prefix: string, size: string, product: Product}>
     * }
     */
    private function buildIndex($products): array
    {
        $codes = [];
        $suffixes = [];
        $prefixes = [];

        foreach ($products as $product) {
            foreach ([$product->product_code, $product->alternative_code, $product->dsv_code] as $code) {
                $normalized = $this->parser->normalizeCode((string) $code);
                if ($normalized !== '' && ! isset($codes[$normalized])) {
                    $codes[$normalized] = $product;
                }
            }

            $product->loadMissing('color.collection');
            $collection = $this->parser->normalizeCode((string) ($product->color?->collection?->name ?? ''));
            $colorCode = $this->parser->normalizeCode((string) ($product->color?->color_code ?? ''));
            $colorName = $this->parser->normalizeCode((string) ($product->color?->color_name ?? ''));
            $size = $this->parser->normalizeCode((string) $product->name);

            $reconstructed = $collection.$colorCode.$colorName.$size;
            if ($reconstructed !== '' && ! isset($codes[$reconstructed])) {
                $codes[$reconstructed] = $product;
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
        }

        usort(
            $prefixes,
            fn (array $left, array $right): int => strlen($right['prefix']) <=> strlen($left['prefix']),
        );

        return [
            'codes' => $codes,
            'suffixes' => $suffixes,
            'prefixes' => $prefixes,
        ];
    }

    /**
     * @param  array{
     *     codes: array<string, Product>,
     *     suffixes: array<string, Product>,
     *     prefixes: list<array{prefix: string, size: string, product: Product}>
     * }  $index
     * @param  EloquentCollection<int, Product>  $products
     */
    private function matchProduct(string $code, ?string $name, array $index, $products): ?Product
    {
        $normalized = $this->parser->normalizeCode($code);

        if ($normalized !== '' && isset($index['codes'][$normalized])) {
            return $index['codes'][$normalized];
        }

        foreach ($index['suffixes'] as $suffix => $product) {
            if (str_ends_with($normalized, $suffix)) {
                return $product;
            }
        }

        foreach ($index['prefixes'] as $candidate) {
            if (str_starts_with($normalized, $candidate['prefix']) && str_ends_with($normalized, $candidate['size'])) {
                return $candidate['product'];
            }
        }

        if (str_contains($normalized, 'KATALOG')) {
            $token = $this->catalogToken($normalized);
            if ($token !== '') {
                foreach ($products as $product) {
                    if (! $product->isCatalog()) {
                        continue;
                    }

                    if ($this->catalogProductMatchesToken($product, $token)) {
                        return $product;
                    }
                }
            }
        }

        if (filled($name)) {
            $nameNorm = $this->parser->normalizeCode($name);
            foreach ($products as $product) {
                if ($product->isCatalog() && $this->parser->normalizeCode((string) $product->name) === $nameNorm) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function catalogToken(string $normalizedCode): string
    {
        if (preg_match('#/?([A-Z0-9]+)/?KATALOG#', $normalizedCode, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function catalogProductMatchesToken(Product $product, string $token): bool
    {
        foreach ([$product->product_code, $product->alternative_code] as $code) {
            $normalized = $this->parser->normalizeCode((string) $code);
            if ($normalized === '') {
                continue;
            }

            if ($this->catalogToken($normalized) === $token) {
                return true;
            }
        }

        $name = $this->parser->normalizeCode((string) $product->name);
        $colorName = $this->parser->normalizeCode((string) ($product->color?->color_name ?? ''));
        $expected = $this->parser->normalizeCode('Katalogas '.$token);

        return $name === $expected || $colorName === $expected;
    }

    private function quantityToStock(Product $product, float $quantity, ?string $unit): int
    {
        $quantity = max(0.0, $quantity);
        $unitNorm = strtolower(trim((string) $unit));
        $isPieces = $product->isCatalog()
            || in_array($unitNorm, ['vnt', 'vnt.', 'pcs', 'pc', 'un', 'unit'], true);

        if ($isPieces) {
            return (int) round($quantity);
        }

        $size = $product->sizeMeters();
        $looksLikeMeters = $unitNorm === '' || in_array($unitNorm, ['m', 'm.', 'lm', 'metr', 'meters'], true);

        if ($size !== null && $looksLikeMeters) {
            return (int) round($quantity / $size);
        }

        return (int) round($quantity);
    }
}
