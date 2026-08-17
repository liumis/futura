<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'from_name',
        'text',
    ];
}
