<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_default',
        'items_on_euroaluse',
        'euroaluse_price',
        'default_buffer',
        'fulfillment_warehouse_email',
        'fulfillment_mail_template_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'items_on_euroaluse' => 'integer',
            'euroaluse_price' => 'decimal:2',
            'default_buffer' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShippingSetting $setting): void {
            if (! $setting->is_default) {
                return;
            }

            static::query()
                ->when($setting->exists, fn ($query) => $query->whereKeyNot($setting->getKey()))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });

        static::deleted(function (ShippingSetting $setting): void {
            if (! $setting->is_default) {
                return;
            }

            $next = static::query()->orderBy('id')->first();
            if ($next !== null) {
                $next->update(['is_default' => true]);
            }
        });
    }

    public function fulfillmentMailTemplate(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class, 'fulfillment_mail_template_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public static function defaultProvider(): self
    {
        $default = static::query()
            ->with('fulfillmentMailTemplate')
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            return $default;
        }

        $first = static::query()
            ->with('fulfillmentMailTemplate')
            ->orderBy('id')
            ->first();

        if ($first !== null) {
            if (! $first->is_default) {
                $first->update(['is_default' => true]);
            }

            return $first->fresh(['fulfillmentMailTemplate']) ?? $first;
        }

        return static::query()->create([
            'name' => 'Default',
            'is_default' => true,
            'items_on_euroaluse' => 1,
            'euroaluse_price' => 0,
            'default_buffer' => 0,
        ])->load('fulfillmentMailTemplate');
    }
}
