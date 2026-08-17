<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OvertimeRequestType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
    ];

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }
}
