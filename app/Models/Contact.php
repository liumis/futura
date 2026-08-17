<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'company_country',
        'company_id',
        'company_vat',
        'company_address',
        'company_email',
        'company_phone',
        'contact_name',
        'contact_email',
        'contact_phone',
    ];
}
