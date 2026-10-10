<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DirectoryContactStage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $stage): void {
            if ($stage->sort_order !== null && (int) $stage->sort_order > 0) {
                return;
            }

            $stage->sort_order = (int) static::query()->max('sort_order') + 1;
        });
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(DirectoryContact::class);
    }

    public static function defaultName(): string
    {
        return 'New';
    }

    public static function default(): self
    {
        $existing = static::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(self::defaultName())])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::query()->create([
            'name' => self::defaultName(),
            'sort_order' => 1,
        ]);
    }

    public static function defaultId(): int
    {
        return (int) self::default()->getKey();
    }
}
