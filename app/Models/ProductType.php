<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductType extends Model
{
    public const KEY_ARTIFICIAL_LEATHER = 'artificial_leather';

    public const KEY_CATALOG = 'catalog';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key',
        'requires_color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_color' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isCatalog(): bool
    {
        return $this->key === self::KEY_CATALOG;
    }

    public function isArtificialLeather(): bool
    {
        return $this->key === self::KEY_ARTIFICIAL_LEATHER;
    }

    public static function artificialLeatherId(): ?int
    {
        $id = static::query()->where('key', self::KEY_ARTIFICIAL_LEATHER)->value('id');

        return filled($id) ? (int) $id : null;
    }

    public static function catalogId(): ?int
    {
        $id = static::query()->where('key', self::KEY_CATALOG)->value('id');

        return filled($id) ? (int) $id : null;
    }
}
