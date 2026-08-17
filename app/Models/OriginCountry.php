<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OriginCountry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Country options keyed by name (values are stored as the country name).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::query()->orderBy('name')->pluck('name', 'name')->all();
    }
}
