<?php

namespace App\Models;

use App\Enums\WarehouseImportStatus;
use App\Services\CargoCostCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseImport extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'cargo_id',
        'cost',
        'base_cost',
        'overhead_cost',
        'received_date',
        'amount',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'base_cost' => 'decimal:2',
            'overhead_cost' => 'decimal:4',
            'received_date' => 'date',
            'amount' => 'integer',
            'status' => WarehouseImportStatus::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function unitCostPerMeter(): ?float
    {
        $this->loadMissing('product');

        $size = (float) ($this->product?->name ?? 0);

        if ($size <= 0) {
            return null;
        }

        return round((float) $this->cost / $size, 2);
    }

    public static function syncFromCargo(Cargo $cargo, ?string $receivedDate = null): void
    {
        $cargo->loadMissing(['cargoItems.product.productType', 'importTax']);

        $receivedDate ??= now()->toDateString();

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $baseCost = $item->unitBaseCost();
            $overheadPerUnit = CargoCostCalculator::itemOverheadPerUnit($cargo, $item);
            $unitCost = CargoCostCalculator::landedUnitCost($cargo, $item);

            $import = self::query()->firstOrNew([
                'cargo_id' => $cargo->id,
                'product_id' => $item->product_id,
            ]);

            $import->fill([
                'base_cost' => $baseCost,
                'overhead_cost' => $overheadPerUnit,
                'cost' => $unitCost,
                'received_date' => $receivedDate,
                'amount' => $item->amount,
            ]);

            // Cargo "Received" creates pending warehouse imports; staff mark them
            // In stock on /warehouse-imports after confirming stock.
            if (! $import->exists) {
                $import->status = WarehouseImportStatus::Pending;
            }

            $import->save();
        }
    }

    /**
     * Add this line to product stock and mark it In stock.
     */
    public function importToStock(): void
    {
        if ($this->status === WarehouseImportStatus::Received) {
            throw new RuntimeException('This line is already in stock.');
        }

        $amount = (int) $this->amount;

        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero before importing.');
        }

        DB::transaction(function () use ($amount): void {
            $product = Product::query()
                ->with(['productType', 'color.collection'])
                ->lockForUpdate()
                ->find($this->product_id);

            if ($product === null) {
                throw new RuntimeException('Product not found.');
            }

            $meters = $product->sizeMeters();
            $incomingQty = ($meters !== null && $meters > 0)
                ? (float) $amount * $meters
                : (float) $amount;
            $landedCost = ($meters !== null && $meters > 0)
                ? round((float) $this->cost / $meters, 2)
                : (float) $this->cost;

            $oldQty = CargoCostCalculator::currentStockQuantity($product);
            $oldCost = $product->collectionUnitCost();
            $mergedCost = CargoCostCalculator::mergeAverageCost($oldCost, $oldQty, $landedCost, $incomingQty);

            $product->forceFill([
                'current_amount' => (int) $product->current_amount + $amount,
                'cost' => $mergedCost,
            ])->save();

            $this->forceFill([
                'status' => WarehouseImportStatus::Received,
            ])->save();
        });
    }

    /**
     * Redistribute cargo overhead across warehouse-import lines by amount × base cost.
     * Locked ("In stock") rows are left unchanged and excluded from the share pool.
     */
    public static function recalculateOverheadsForCargo(?int $cargoId): void
    {
        if ($cargoId === null || $cargoId <= 0) {
            return;
        }

        $cargo = Cargo::query()
            ->with(['importTax', 'cargoItems.product.productType'])
            ->find($cargoId);

        if ($cargo === null) {
            return;
        }

        $imports = self::query()
            ->where('cargo_id', $cargoId)
            ->get();

        $editable = $imports->filter(
            fn (self $import): bool => $import->status !== WarehouseImportStatus::Received,
        );

        if ($editable->isEmpty()) {
            return;
        }

        $overheadTotal = CargoCostCalculator::overheadTotal($cargo);

        $goodsTotal = (float) $editable->sum(
            fn (self $import): float => max(0, (int) $import->amount) * (float) $import->base_cost,
        );
        $qtyTotal = (int) $editable->sum(
            fn (self $import): int => max(0, (int) $import->amount),
        );

        foreach ($editable as $import) {
            $amount = max(0, (int) $import->amount);
            $base = (float) $import->base_cost;

            if ($amount <= 0 || $overheadTotal <= 0) {
                $overheadPerUnit = 0.0;
            } elseif ($goodsTotal > 0) {
                $lineGoods = $amount * $base;
                $overheadPerUnit = round(($overheadTotal * ($lineGoods / $goodsTotal)) / $amount, 4);
            } elseif ($qtyTotal > 0) {
                $overheadPerUnit = round($overheadTotal / $qtyTotal, 4);
            } else {
                $overheadPerUnit = 0.0;
            }

            $import->forceFill([
                'overhead_cost' => $overheadPerUnit,
                'cost' => round($base + $overheadPerUnit, 2),
            ])->save();
        }
    }
}
