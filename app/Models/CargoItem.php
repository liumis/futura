<?php

namespace App\Models;

use Database\Factories\CargoItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CargoItem extends Model
{
    /** @use HasFactory<CargoItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cargo_id',
        'product_id',
        'amount',
        'self_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'self_cost' => 'decimal:2',
        ];
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function goodsValue(): float
    {
        $this->loadMissing('product.productType');

        if ($this->product instanceof Product) {
            return $this->product->lineAmount((float) $this->self_cost, (int) $this->amount);
        }

        return round((int) $this->amount * (float) $this->self_cost, 2);
    }

    /**
     * Purchase cost of one ordered unit (one roll when size is in meters).
     */
    public function unitBaseCost(): float
    {
        $this->loadMissing('product.productType');

        if ($this->product instanceof Product) {
            return $this->product->lineAmount((float) $this->self_cost, 1);
        }

        return round((float) $this->self_cost, 2);
    }
}
