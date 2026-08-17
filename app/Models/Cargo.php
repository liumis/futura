<?php

namespace App\Models;

use App\Enums\CargoStatus;
use Database\Factories\CargoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cargo extends Model
{
    /** @use HasFactory<CargoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'tracking',
        'date_shipped',
        'estimated_arrival',
        'status',
        'email_sent_at',
        'import_tax_id',
        'shipping_cost',
        'additional_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_shipped' => 'date',
            'estimated_arrival' => 'date',
            'status' => CargoStatus::class,
            'email_sent_at' => 'datetime',
            'shipping_cost' => 'decimal:2',
            'additional_cost' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function importTax(): BelongsTo
    {
        return $this->belongsTo(ImportTax::class);
    }

    public function cargoItems(): HasMany
    {
        return $this->hasMany(CargoItem::class);
    }
}
