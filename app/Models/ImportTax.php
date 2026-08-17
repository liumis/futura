<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportTax extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }
}
