<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'total_weight',
        'plastic_weight',
        'cardboard_i_weight',
        'cardboard_ii_weight',
        'items_on_palette',
        'palette_weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_weight' => 'decimal:3',
            'plastic_weight' => 'decimal:3',
            'cardboard_i_weight' => 'decimal:3',
            'cardboard_ii_weight' => 'decimal:3',
            'items_on_palette' => 'integer',
            'palette_weight' => 'decimal:3',
        ];
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'default_package_id');
    }
}
