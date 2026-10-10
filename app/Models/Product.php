<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'cost',
        'current_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_amount' => 'integer',
            'cost' => 'decimal:2',
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

    public function reservation(): HasOne
    {
        return $this->hasOne(ProductReservation::class);
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

    /**
     * Roll length in meters when Name / Size is numeric. Catalog products are sold per piece.
     */
    public function sizeMeters(): ?float
    {
        if ($this->isCatalog() || ! is_numeric($this->name)) {
            return null;
        }

        $meters = (float) $this->name;

        return $meters > 0 ? $meters : null;
    }

    /**
     * Convert a meter quantity into roll/piece amount when size divides evenly.
     * Historical imports often provide meters; live orders store roll counts.
     */
    public function rollAmountFromMeters(int $meters): int
    {
        $meters = max(0, $meters);
        $size = $this->sizeMeters();

        if ($size === null) {
            return $meters;
        }

        $rolls = $meters / $size;
        if (abs($rolls - round($rolls)) > 0.001) {
            return $meters;
        }

        return max(0, (int) round($rolls));
    }

    /**
     * Line value for a unit price that is per meter on sized leather, or per piece otherwise.
     */
    public static function lineTotalForSize(float $unitPrice, mixed $size, int $quantity): float
    {
        $meters = is_numeric($size) ? (float) $size : 0.0;
        $quantity = max(0, $quantity);

        if ($meters > 0) {
            return round($unitPrice * $meters * $quantity, 2);
        }

        return round($unitPrice * $quantity, 2);
    }

    /**
     * Selling / unit price per meter from the collection. Catalog products have no collection price.
     */
    public function collectionUnitPrice(): float
    {
        $this->loadMissing('color.collection');

        return (float) ($this->color?->collection?->price ?? 0);
    }

    /**
     * Purchase cost per meter from the product, then the collection. Catalogs use the product cost per piece.
     */
    public function collectionUnitCost(): float
    {
        if ($this->isCatalog()) {
            return (float) ($this->cost ?? 0);
        }

        if ((float) ($this->cost ?? 0) > 0) {
            return (float) $this->cost;
        }

        $this->loadMissing('color.collection');

        return (float) ($this->color?->collection?->cost ?? 0);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    public static function collectionCostsByIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return static::query()
            ->with('color.collection')
            ->whereIn('id', $productIds)
            ->get()
            ->mapWithKeys(fn (self $product): array => [
                (int) $product->id => $product->collectionUnitCost(),
            ])
            ->all();
    }

    public function lineAmount(?float $unitPrice = null, int $quantity = 1): float
    {
        $unit = $unitPrice ?? $this->collectionUnitPrice();
        $meters = $this->sizeMeters();

        if ($meters === null) {
            return round($unit * max(0, $quantity), 2);
        }

        return self::lineTotalForSize($unit, $meters, $quantity);
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
        $name = 'products.name';
        $amount = 'products.current_amount';

        return match (DB::getDriverName()) {
            'sqlite' => "CAST({$name} AS REAL) * {$amount}",
            default => "CAST({$name} AS DECIMAL(12,3)) * {$amount}",
        };
    }

    public function scopeBelowStockMeterLimit(Builder $query, float $limit): Builder
    {
        if ($limit <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $meters = '('.self::stockMetersSqlExpression().')';
        $limitSql = match (DB::getDriverName()) {
            'sqlite' => 'CAST(? AS REAL)',
            default => 'CAST(? AS DECIMAL(12,3))',
        };

        return $query
            ->where(function (Builder $inner): void {
                $inner->whereHas('productType', fn (Builder $type) => $type->where('requires_color', true))
                    ->orWhereDoesntHave('productType');
            })
            ->whereRaw($meters.' < '.$limitSql, [$limit]);
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
