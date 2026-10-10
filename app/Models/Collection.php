<?php

namespace App\Models;

use App\Services\CollectionPricing;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'name',
        'price',
        'cost',
        'port_price',
        'agent_fee',
        'other_cost',
        'markup_percent',
        'country_of_origin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'port_price' => 'decimal:4',
            'agent_fee' => 'decimal:4',
            'other_cost' => 'decimal:4',
            'markup_percent' => 'decimal:2',
        ];
    }

    public function applyPricingBreakdown(): void
    {
        $totals = CollectionPricing::syncTotals(
            $this->port_price !== null ? (float) $this->port_price : null,
            $this->agent_fee !== null ? (float) $this->agent_fee : null,
            $this->other_cost !== null ? (float) $this->other_cost : null,
            $this->markup_percent !== null ? (float) $this->markup_percent : null,
        );

        if ($totals['cost'] !== null) {
            $this->cost = $totals['cost'];
        }

        if ($totals['price'] !== null) {
            $this->price = $totals['price'];
        } elseif ($this->markup_percent !== null && $this->cost !== null) {
            $this->price = CollectionPricing::sellPrice(
                (float) $this->cost,
                (float) $this->markup_percent,
            );
        }
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function colors(): HasMany
    {
        return $this->hasMany(Color::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, Color::class);
    }

    public function customerLevelPrices(): HasMany
    {
        return $this->hasMany(CustomerLevelPrice::class);
    }
}
