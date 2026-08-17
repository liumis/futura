<?php

namespace App\Models;

use Database\Factories\CustomerLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLevel extends Model
{
    /** @use HasFactory<CustomerLevelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function customerLevelPrices(): HasMany
    {
        return $this->hasMany(CustomerLevelPrice::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
