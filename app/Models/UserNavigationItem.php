<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNavigationItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'item_key',
        'visible',
        'sort',
        'custom_label',
        'group_id',
        'is_landing',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'sort' => 'integer',
            'is_landing' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserNavigationGroup::class, 'group_id');
    }
}
