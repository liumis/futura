<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncomeType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
