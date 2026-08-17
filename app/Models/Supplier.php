<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'default_package_id',
        'mail_template_id',
    ];

    public function defaultPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'default_package_id');
    }

    public function mailTemplate(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }
}
