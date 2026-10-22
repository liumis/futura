<?php

namespace App\Models;

use Database\Factories\ColorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Color extends Model
{
    /** @use HasFactory<ColorFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'collection_id',
        'color_code',
        'color_name',
        'recommended_stock',
        'default_order',
        'image',
        'hex',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recommended_stock' => 'integer',
            'default_order' => 'integer',
        ];
    }

    public static function storageDisk(): string
    {
        $default = (string) config('filesystems.default', 'local');

        return $default === 's3' ? 's3' : 'public';
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        $disk = self::storageDisk();
        $storage = Storage::disk($disk);

        if ($disk === 's3') {
            try {
                return $storage->temporaryUrl($this->image, now()->addHours(24));
            } catch (\Throwable) {
                // Fall through to a public URL if the driver cannot sign requests.
            }
        }

        return $storage->url($this->image);
    }

    public function normalizedHex(): ?string
    {
        $hex = strtoupper(trim((string) ($this->hex ?? '')));

        if ($hex !== '' && ! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        if ($hex === '' || ! preg_match('/^#[0-9A-F]{6}$/', $hex)) {
            return null;
        }

        return $hex;
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Total stock meters for this color (artificial leather products only).
     */
    public function stockMeters(): float
    {
        return round((float) $this->products()
            ->artificialLeather()
            ->get()
            ->sum(fn (Product $product): float => $product->stockMeters()), 3);
    }

    /**
     * SQL subquery: sum of artificial leather product stock meters for colors.id.
     */
    public static function stockMetersSqlExpression(): string
    {
        $meters = Product::stockMetersSqlExpression();
        $leatherKey = ProductType::KEY_ARTIFICIAL_LEATHER;

        return "(
            SELECT COALESCE(SUM({$meters}), 0)
            FROM products
            INNER JOIN product_types ON product_types.id = products.product_type_id
            WHERE products.color_id = colors.id
              AND product_types.`key` = '{$leatherKey}'
        )";
    }

    /**
     * Artificial-leather colors whose total stock meters are below recommended_stock.
     */
    public function scopeBelowRecommendedStock(Builder $query): Builder
    {
        $meters = self::stockMetersSqlExpression();

        return $query
            ->where('recommended_stock', '>', 0)
            ->whereHas('products', fn (Builder $products): Builder => $products->artificialLeather())
            ->whereRaw("{$meters} < colors.recommended_stock");
    }

    /**
     * @param  list<int>  $colorIds
     * @return array<int, float> color_id => total stock meters
     */
    public static function stockMetersByIds(array $colorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $colorIds))));
        if ($ids === []) {
            return [];
        }

        $metersSql = self::stockMetersSqlExpression();

        return self::query()
            ->whereIn('id', $ids)
            ->select('id')
            ->selectRaw("{$metersSql} as stock_meters_total")
            ->pluck('stock_meters_total', 'id')
            ->map(fn ($meters): float => round((float) $meters, 3))
            ->all();
    }
}
