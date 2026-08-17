<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WriteOffDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_number',
        'document_date',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stockManualUpdates(): HasMany
    {
        return $this->hasMany(StockManualUpdate::class);
    }

    public function totalWrittenOffUnits(): int
    {
        return (int) $this->stockManualUpdates
            ->sum(fn (StockManualUpdate $update): int => abs(min(0, $update->delta())));
    }
}
