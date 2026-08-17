<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomsContact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'company_code',
        'vat_code',
        'address',
        'phone',
        'email',
    ];
}
