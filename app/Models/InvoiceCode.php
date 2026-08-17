<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceCode extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'parent_id',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function financeLines(): HasMany
    {
        return $this->hasMany(InvoiceFinanceLine::class);
    }

    public function depth(): int
    {
        $depth = 0;
        $parent = $this->parent;

        while ($parent !== null) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    /**
     * @return list<int>
     */
    public function descendantIds(): array
    {
        return self::descendantIdsFor($this->id);
    }

    /**
     * @return list<int>
     */
    public static function descendantIdsFor(int $id): array
    {
        $ids = [];
        $childIds = self::query()
            ->where('parent_id', $id)
            ->pluck('id')
            ->all();

        foreach ($childIds as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, self::descendantIdsFor($childId));
        }

        return $ids;
    }

    public function indentedLabel(): string
    {
        return str_repeat('— ', $this->depth()).$this->code.' '.$this->name;
    }
}
