<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceSeries extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'prefix',
        'first_item_no',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_item_no' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (InvoiceSeries $series): void {
            if (! $series->is_default) {
                return;
            }

            static::query()
                ->whereKeyNot($series->getKey())
                ->update(['is_default' => false]);
        });
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function nextSeriesNumber(): int
    {
        $lastNumber = $this->invoices()->max('series_number');

        if ($lastNumber === null) {
            return (int) $this->first_item_no;
        }

        return (int) $lastNumber + 1;
    }

    public function formatNumber(int $seriesNumber): string
    {
        return $this->prefix.$seriesNumber;
    }
}
