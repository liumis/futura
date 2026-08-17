<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'is_default',
        'mail_template_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Warehouse $warehouse): void {
            if (! $warehouse->is_default) {
                return;
            }

            static::query()
                ->when($warehouse->exists, fn ($query) => $query->whereKeyNot($warehouse->getKey()))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });

        static::deleted(function (Warehouse $warehouse): void {
            if (! $warehouse->is_default) {
                return;
            }

            $next = static::query()->orderBy('id')->first();
            if ($next !== null) {
                $next->update(['is_default' => true]);
            }
        });
    }

    public function mailTemplate(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public static function defaultWarehouse(): ?self
    {
        $default = static::query()
            ->with('mailTemplate')
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            return $default;
        }

        $first = static::query()
            ->with('mailTemplate')
            ->orderBy('id')
            ->first();

        if ($first === null) {
            return null;
        }

        if (! $first->is_default) {
            $first->update(['is_default' => true]);
        }

        return $first->fresh(['mailTemplate']) ?? $first;
    }
}
