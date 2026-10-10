<?php

namespace App\Models;

use Database\Factories\ProductReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReservation extends Model
{
    /** @use HasFactory<ProductReservationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'quantity',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quantityMeters(): ?float
    {
        $product = $this->product;
        $size = $product?->sizeMeters();

        if ($size === null) {
            return null;
        }

        return $size * max(0, (int) $this->quantity);
    }
}
