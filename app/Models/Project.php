<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
