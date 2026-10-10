<?php

namespace App\Services;

use App\Enums\CargoStatus;
use App\Enums\WarehouseImportStatus;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Collection;
use App\Models\Color;
use App\Models\ImportTax;
use App\Models\ImportTaxPayment;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Supplier;
use App\Models\WarehouseImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class MayurWarehouseCargoImporter
{
    /**
     * @return array{
     *     cargos_created: int,
     *     cargos_skipped: int,
     *     items_created: int,
     *     imports_created: int,
     *     lines_matched: int,
     *     lines_unmatched: int,
     *     unmatched_samples: list<string>,
     *     products_created: int,
     *     colors_created: int,
     *     collections_created: int,
     *     documents: list<array{doc: string, date: ?string, lines: int, rolls: int}>
     * }
     */
    public function import(string $prekesPath, bool $dryRun = false, bool $createMissingProducts = false): array
    {
        if (! is_file($prekesPath)) {
            throw new RuntimeException('File not found: '.$prekesPath);
        }

        $supplier = Supplier::query()
            ->where('name', 'like', '%Mayur%')
            ->orderBy('id')
            ->first();

        if ($supplier === null) {
            throw new RuntimeException('Mayur supplier not found.');
        }

        $createdMeta = [
            'products_created' => 0,
            'colors_created' => 0,
            'collections_created' => 0,
        ];

        if ($createMissingProducts && ! $dryRun) {
            $createdMeta = $this->createMissingProducts($prekesPath, (int) $supplier->id);
        }

        $documents = $this->parsePrekes($prekesPath);
        $result = [
            'cargos_created' => 0,
            'cargos_skipped' => 0,
            'items_created' => 0,
            'imports_created' => 0,
            'lines_matched' => 0,
            'lines_unmatched' => $documents['unmatched'],
            'unmatched_samples' => $documents['unmatched_samples'],
            'products_created' => $createdMeta['products_created'],
            'colors_created' => $createdMeta['colors_created'],
            'collections_created' => $createdMeta['collections_created'],
            'documents' => [],
        ];

        foreach ($documents['docs'] as $doc => $data) {
            $rolls = (int) array_sum(array_map(fn (array $line): int => $line['rolls'], $data['lines']));
            $result['lines_matched'] += count($data['lines']);
            $result['documents'][] = [
                'doc' => (string) $doc,
                'date' => $data['date'],
                'lines' => count($data['lines']),
                'rolls' => $rolls,
            ];

            if ($dryRun) {
                continue;
            }

            $existing = Cargo::query()
                ->where('supplier_id', $supplier->id)
                ->where('tracking', (string) $doc)
                ->first();

            if ($existing !== null) {
                $result['cargos_skipped']++;
                $attached = $this->attachMissingLinesToCargo($existing, $data);
                $result['items_created'] += $attached['items_created'];
                $result['imports_created'] += $attached['imports_created'];

                continue;
            }

            DB::transaction(function () use ($supplier, $doc, $data, &$result): void {
                $date = $data['date'] ?? now()->toDateString();
                $defaultImportTaxId = ImportTax::query()
                    ->orderByDesc('rate')
                    ->orderBy('id')
                    ->value('id');

                $cargo = Cargo::query()->create([
                    'supplier_id' => $supplier->id,
                    'tracking' => (string) $doc,
                    'date_shipped' => $date,
                    'estimated_arrival' => $date,
                    'actual_date' => $date,
                    'status' => CargoStatus::Received,
                    'import_tax_id' => $defaultImportTaxId,
                    'shipping_cost' => 0,
                    'additional_cost' => 0,
                ]);

                $result['cargos_created']++;

                $attached = $this->attachMissingLinesToCargo($cargo, $data);
                $result['items_created'] += $attached['items_created'];
                $result['imports_created'] += $attached['imports_created'];
            });
        }

        return $result;
    }

    /**
     * Create collections / colors / size products for Mayur lines that have no catalog match.
     *
     * @return array{products_created: int, colors_created: int, collections_created: int}
     */
    public function createMissingProducts(string $prekesPath, ?int $supplierId = null): array
    {
        $supplierId ??= (int) Supplier::query()
            ->where('name', 'like', '%Mayur%')
            ->orderBy('id')
            ->value('id');

        if ($supplierId <= 0) {
            throw new RuntimeException('Mayur supplier not found.');
        }

        $missing = $this->collectMissingSkuSpecs($prekesPath);
        $index = $this->productIndex();
        $productTypeId = ProductType::query()->where('name', 'Artificial leather')->value('id')
            ?? ProductType::query()->orderBy('id')->value('id');

        $template = Collection::query()
            ->where('supplier_id', $supplierId)
            ->where('name', 'Agnona')
            ->first();

        $stats = [
            'products_created' => 0,
            'colors_created' => 0,
            'collections_created' => 0,
        ];

        DB::transaction(function () use ($missing, &$index, $supplierId, $productTypeId, $template, &$stats): void {
            foreach ($missing as $spec) {
                $key = $spec['collection'].'|'.$spec['color_code'].'|'.$spec['size'];
                if (isset($index[$key])) {
                    continue;
                }

                $collection = $this->findOrCreateCollection(
                    $spec['collection'],
                    $supplierId,
                    $template,
                    $stats,
                );

                $color = Color::query()
                    ->where('collection_id', $collection->id)
                    ->where('color_code', $spec['color_code'])
                    ->first();

                if ($color === null) {
                    $color = Color::query()->create([
                        'collection_id' => $collection->id,
                        'color_code' => $spec['color_code'],
                        'color_name' => $this->formatColorName($spec['color_name']),
                        'recommended_stock' => 0,
                        'default_order' => 0,
                    ]);
                    $stats['colors_created']++;
                }

                $sizeLabel = rtrim(rtrim(number_format((float) $spec['size'], 2, '.', ''), '0'), '.') ?: (string) $spec['size'];
                $exists = Product::query()
                    ->where('color_id', $color->id)
                    ->where('name', $sizeLabel)
                    ->exists();

                if ($exists) {
                    $index = $this->productIndex();

                    continue;
                }

                $colorNameCompact = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $color->color_name) ?? '');
                $alternative = strtoupper($spec['collection']).$spec['color_code'].$colorNameCompact.$sizeLabel;

                Product::query()->create([
                    'product_type_id' => $productTypeId,
                    'name' => $sizeLabel,
                    'color_id' => $color->id,
                    'alternative_code' => $alternative,
                    'cost' => (float) ($collection->cost ?? $template?->cost ?? 0),
                    'current_amount' => 0,
                ]);
                $stats['products_created']++;
                $index = $this->productIndex();
            }
        });

        return $stats;
    }

    /**
     * @param  array{date: ?string, lines: list<array{product_id: int, qty_m: float, rolls: int, self_cost: float, sum: float}>}  $data
     * @return array{items_created: int, imports_created: int}
     */
    private function attachMissingLinesToCargo(Cargo $cargo, array $data): array
    {
        $itemsCreated = 0;
        $importsCreated = 0;
        $date = $data['date'] ?? $cargo->actual_date?->toDateString() ?? now()->toDateString();

        $aggregated = [];
        foreach ($data['lines'] as $line) {
            $pid = (int) $line['product_id'];
            if (! isset($aggregated[$pid])) {
                $aggregated[$pid] = [
                    'product_id' => $pid,
                    'rolls' => 0,
                    'meters' => 0.0,
                    'value' => 0.0,
                    'self_cost' => (float) $line['self_cost'],
                ];
            }
            $aggregated[$pid]['rolls'] += (int) $line['rolls'];
            $aggregated[$pid]['meters'] += (float) $line['qty_m'];
            $aggregated[$pid]['value'] += (float) $line['sum'];
        }

        $existingProductIds = CargoItem::query()
            ->where('cargo_id', $cargo->id)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $existingSet = array_fill_keys($existingProductIds, true);

        foreach ($aggregated as $line) {
            $productId = (int) $line['product_id'];
            if (isset($existingSet[$productId])) {
                continue;
            }

            $rolls = max(1, (int) $line['rolls']);
            $selfCost = $line['meters'] > 0
                ? round($line['value'] / $line['meters'], 2)
                : round((float) ($line['self_cost'] ?? 0), 2);

            if ($selfCost <= 0 && $line['value'] > 0 && $rolls > 0) {
                $product = Product::query()->find($productId);
                $size = (float) ($product?->name ?? 0);
                if ($size > 0) {
                    $selfCost = round($line['value'] / ($size * $rolls), 2);
                }
            }

            CargoItem::query()->create([
                'cargo_id' => $cargo->id,
                'product_id' => $productId,
                'amount' => $rolls,
                'self_cost' => $selfCost,
            ]);
            $itemsCreated++;

            $baseCost = $selfCost;
            $product = Product::query()->find($productId);
            if ($product !== null) {
                $baseCost = $product->lineAmount($selfCost, 1);
            }

            WarehouseImport::query()->create([
                'product_id' => $productId,
                'cargo_id' => $cargo->id,
                'cost' => $baseCost,
                'base_cost' => $baseCost,
                'overhead_cost' => 0,
                'received_date' => $date,
                'amount' => $rolls,
                'status' => WarehouseImportStatus::Received,
            ]);
            $importsCreated++;
        }

        if ($itemsCreated > 0) {
            $cargo->load(['cargoItems.product.productType', 'importTax']);
            ImportTaxPayment::syncFromCargo($cargo);
        }

        return [
            'items_created' => $itemsCreated,
            'imports_created' => $importsCreated,
        ];
    }

    /**
     * @return list<array{collection: string, color_code: string, color_name: string, size: string}>
     */
    private function collectMissingSkuSpecs(string $path): array
    {
        $productIndex = $this->productIndex();
        $sheetRows = $this->loadRows($path);
        $missing = [];
        $current = null;

        foreach ($sheetRows as $row) {
            $c0 = trim((string) ($row[0] ?? ''));

            if ($c0 !== '' && ! is_numeric(str_replace([',', ' '], ['.', ''], $c0)) && (str_contains($c0, '/') || preg_match('/^[A-Z]/i', $c0))) {
                if (str_contains(strtoupper($c0), 'ASSORTED')) {
                    $current = null;

                    continue;
                }

                $current = $this->parseProductHeaderPreferDescriptive($c0);

                continue;
            }

            if ($current === null) {
                continue;
            }

            $doc = trim((string) ($row[4] ?? ''));
            $qty = (float) str_replace([',', ' '], ['.', ''], (string) ($row[6] ?? ''));
            if ($doc === '' || $qty <= 0) {
                continue;
            }

            $collection = $this->normalizeCollectionKey($current['collection']);
            $key = $collection.'|'.$current['color_code'].'|'.$current['size'];

            if (isset($productIndex[$key]) || isset($missing[$key])) {
                continue;
            }

            $missing[$key] = [
                'collection' => $collection,
                'color_code' => $current['color_code'],
                'color_name' => $current['color_name'],
                'size' => $current['size'],
            ];
        }

        ksort($missing);

        return array_values($missing);
    }

    /**
     * @return array{
     *     docs: array<string, array{date: ?string, lines: list<array{product_id: int, qty_m: float, rolls: int, self_cost: float, sum: float}>}>,
     *     unmatched: int,
     *     unmatched_samples: list<string>
     * }
     */
    public function parsePrekes(string $path): array
    {
        $productIndex = $this->productIndex();
        $sheetRows = $this->loadRows($path);

        $docs = [];
        $unmatched = 0;
        $unmatchedSamples = [];
        $current = null;

        foreach ($sheetRows as $row) {
            $c0 = trim((string) ($row[0] ?? ''));

            if ($c0 !== '' && ! is_numeric(str_replace([',', ' '], ['.', ''], $c0)) && (str_contains($c0, '/') || preg_match('/^[A-Z]/i', $c0))) {
                if (str_contains(strtoupper($c0), 'ASSORTED')) {
                    $current = null;

                    continue;
                }

                $current = $this->parseProductHeaderPreferDescriptive($c0);

                continue;
            }

            if ($current === null) {
                continue;
            }

            $doc = trim((string) ($row[4] ?? ''));
            $qty = (float) str_replace([',', ' '], ['.', ''], (string) ($row[6] ?? ''));
            $unit = (float) str_replace([',', ' '], ['.', ''], (string) ($row[9] ?? ''));
            $sum = (float) str_replace([',', ' '], ['.', ''], (string) ($row[10] ?? ($row[11] ?? '0')));
            $date = $this->excelDate($row[0] ?? null);

            if ($doc === '' || $qty <= 0) {
                continue;
            }

            $collection = $this->normalizeCollectionKey($current['collection']);
            $key = $collection.'|'.$current['color_code'].'|'.$current['size'];
            $productId = $productIndex[$key] ?? null;

            if ($productId === null) {
                $unmatched++;
                if (count($unmatchedSamples) < 20) {
                    $unmatchedSamples[] = "{$doc} {$date} {$key} qty={$qty}";
                }

                continue;
            }

            $size = (float) $current['size'];
            $rolls = (int) round($qty / max($size, 0.01));
            if ($rolls < 1) {
                $rolls = 1;
            }

            $selfCost = $unit > 0
                ? round($unit, 2)
                : ($qty > 0 ? round($sum / $qty, 2) : 0.0);

            $docs[$doc]['date'] ??= $date;
            $docs[$doc]['lines'][] = [
                'product_id' => $productId,
                'qty_m' => $qty,
                'rolls' => $rolls,
                'self_cost' => $selfCost,
                'sum' => $sum > 0 ? $sum : round($selfCost * $qty, 2),
            ];
        }

        ksort($docs);

        return [
            'docs' => $docs,
            'unmatched' => $unmatched,
            'unmatched_samples' => $unmatchedSamples,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function productIndex(): array
    {
        $products = Product::query()
            ->whereHas('color.collection', fn ($q) => $q->where('supplier_id', 1))
            ->with(['color:id,color_code,collection_id', 'color.collection:id,name'])
            ->get(['id', 'name', 'color_id']);

        $index = [];
        foreach ($products as $product) {
            $collection = strtoupper((string) ($product->color?->collection?->name ?? ''));
            $code = (string) ($product->color?->color_code ?? '');
            $size = (string) (float) $product->name;
            $index["{$collection}|{$code}|{$size}"] = (int) $product->id;
        }

        return $index;
    }

    /**
     * @return array{collection: string, color_code: string, color_name: string, size: string}|null
     */
    private function parseProductHeaderPreferDescriptive(string $header): ?array
    {
        if (str_contains($header, ' - ')) {
            $descriptive = trim(explode(' - ', $header, 2)[1] ?? '');
            $parsed = $this->parseProductHeader($descriptive);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->parseProductHeader($header);
    }

    /**
     * @return array{collection: string, color_code: string, color_name: string, size: string}|null
     */
    private function parseProductHeader(string $header): ?array
    {
        $header = trim(preg_replace('/\s+/', ' ', $header) ?? $header);

        if (preg_match('/^(?:Dirbtinė oda\s+)?([A-Z0-9]+)\s+(\d{3,4})-+([A-Z0-9.\s]+?)\s+(\d+(?:\.\d+)?)\s*m\b/i', $header, $m)) {
            return [
                'collection' => strtoupper($m[1]),
                'color_code' => $m[2],
                'color_name' => strtoupper(trim($m[3])),
                'size' => (string) (float) $m[4],
            ];
        }

        $compact = preg_replace('/\s+/', '', $header) ?? $header;
        if (preg_match('/^([A-Z]+)(\d{3,4})([A-Z]+)(\d+(?:\.\d+)?)\b/i', $compact, $m)) {
            return [
                'collection' => strtoupper($m[1]),
                'color_code' => $m[2],
                'color_name' => strtoupper($m[3]),
                'size' => (string) (float) $m[4],
            ];
        }

        return null;
    }

    private function normalizeCollectionKey(string $collection): string
    {
        return match (strtoupper($collection)) {
            '25229E' => 'PALOMA',
            default => strtoupper($collection),
        };
    }

    /**
     * @param  array{products_created: int, colors_created: int, collections_created: int}  $stats
     */
    private function findOrCreateCollection(
        string $collectionKey,
        int $supplierId,
        ?Collection $template,
        array &$stats,
    ): Collection {
        $name = Str::title(strtolower($collectionKey));

        $existing = Collection::query()
            ->where('supplier_id', $supplierId)
            ->whereRaw('UPPER(name) = ?', [strtoupper($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $collection = Collection::query()->create([
            'supplier_id' => $supplierId,
            'warehouse_id' => $template?->warehouse_id ?? 1,
            'name' => $name,
            'price' => $template?->price ?? 5,
            'cost' => $template?->cost ?? 5,
            'port_price' => $template?->port_price,
            'agent_fee' => $template?->agent_fee ?? 0.2,
            'other_cost' => $template?->other_cost,
            'markup_percent' => $template?->markup_percent,
            'country_of_origin' => $template?->country_of_origin ?? 'India',
        ]);
        $stats['collections_created']++;

        return $collection;
    }

    private function formatColorName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $name = str_replace(['.', '_'], [' ', ' '], $name);

        return collect(explode(' ', strtolower($name)))
            ->filter()
            ->map(fn (string $word): string => ucfirst($word))
            ->implode(' ');
    }

    private function excelDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function loadRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->load($path)->getActiveSheet()->toArray(null, true, true, false);
    }
}
