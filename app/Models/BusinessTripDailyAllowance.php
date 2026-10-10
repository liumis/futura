<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessTripDailyAllowance extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'list_number',
        'sort_order',
        'country',
        'daily_rate',
    ];

    protected function casts(): array
    {
        return [
            'list_number' => 'integer',
            'sort_order' => 'integer',
            'daily_rate' => 'decimal:2',
        ];
    }
}
