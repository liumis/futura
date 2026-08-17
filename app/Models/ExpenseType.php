<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
