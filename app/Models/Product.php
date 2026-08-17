<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'name' => '20',
        'current_amount' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_type_id',
        'name',
        'color_id',
        'product_code',
        'alternative_code',
        'dsv_code',
        'default_cost',
        'current_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_cost' => 'decimal:2',
            'current_amount' => 'integer',
        ];
    }

    public static function collectionPrefix(string $collectionName): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $collectionName) ?? '';

        return Str::upper(Str::substr($letters, 0, 2));
    }

    public static function generateProductCode(Color $color, string $amount): string
    {
        $color->loadMissing('collection');
        $prefix = self::collectionPrefix($color->collection?->name ?? '');

        return $prefix.$color->color_code.'-'.$amount;
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->isCatalog()) {
                $product->color_id = null;
            }
        });

        static::creating(function (Product $product): void {
            if ($product->isCatalog()) {
                if (blank($product->name)) {
                    $product->name = 'Catalog product';
                }

                if (blank($product->product_code)) {
                    $product->product_code = Str::upper(Str::random(8));
                }

                return;
            }

            if (blank($product->name)) {
                $product->name = '20';
            }

            if (blank($product->product_code) && filled($product->color_id)) {
                $color = Color::query()->with('collection')->find($product->color_id);

                if ($color !== null) {
                    $product->product_code = self::generateProductCode($color, (string) $product->name);
                }
            }

            if (blank($product->product_code)) {
                $product->product_code = Str::upper(Str::random(8));
            }
        });
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function cargoItems(): HasMany
    {
        return $this->hasMany(CargoItem::class);
    }

    public function isCatalog(): bool
    {
        if ($this->relationLoaded('productType') && $this->productType !== null) {
            return $this->productType->isCatalog();
        }

        if (filled($this->product_type_id)) {
            $catalogId = ProductType::catalogId();

            return $catalogId !== null && (int) $this->product_type_id === $catalogId;
        }

        return false;
    }

    public function requiresColor(): bool
    {
        if ($this->relationLoaded('productType') && $this->productType !== null) {
            return (bool) $this->productType->requires_color;
        }

        if (filled($this->product_type_id)) {
            $requires = ProductType::query()->whereKey($this->product_type_id)->value('requires_color');

            if ($requires !== null) {
                return (bool) $requires;
            }
        }

        return filled($this->color_id);
    }

    public function stockMeters(): float
    {
        if ($this->isCatalog()) {
            return (float) $this->current_amount;
        }

        return (float) $this->name * (int) $this->current_amount;
    }

    public static function stockMetersSqlExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => 'CAST(name AS REAL) * current_amount',
            default => 'CAST(name AS DECIMAL(12,3)) * current_amount',
        };
    }

    public function scopeBelowStockMeterLimit(Builder $query, float $limit): Builder
    {
        if ($limit <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(function (Builder $inner): void {
                $inner->whereHas('productType', fn (Builder $type) => $type->where('requires_color', true))
                    ->orWhereDoesntHave('productType');
            })
            ->whereRaw(self::stockMetersSqlExpression().' < ?', [$limit]);
    }

    public function scopeCatalog(Builder $query): Builder
    {
        return $query->whereHas('productType', fn (Builder $type) => $type->where('key', ProductType::KEY_CATALOG));
    }

    public function scopeArtificialLeather(Builder $query): Builder
    {
        return $query->whereHas(
            'productType',
            fn (Builder $type) => $type->where('key', ProductType::KEY_ARTIFICIAL_LEATHER),
        );
    }
}
