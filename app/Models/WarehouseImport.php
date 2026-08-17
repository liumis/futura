<?php

namespace App\Models;

use App\Enums\WarehouseImportStatus;
use App\Services\CargoCostCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $cargo->loadMissing(['cargoItems', 'importTax']);

        $receivedDate ??= now()->toDateString();
        $overheadPerUnit = CargoCostCalculator::overheadPerUnit($cargo);

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $baseCost = round((float) $item->self_cost, 2);
            $unitCost = round($baseCost + $overheadPerUnit, 2);

            self::query()->updateOrCreate(
                [
                    'cargo_id' => $cargo->id,
                    'product_id' => $item->product_id,
                ],
                [
                    'base_cost' => $baseCost,
                    'overhead_cost' => $overheadPerUnit,
                    'cost' => $unitCost,
                    'received_date' => $receivedDate,
                    'amount' => $item->amount,
                    'status' => WarehouseImportStatus::Received,
                ],
            );
        }
    }
}
