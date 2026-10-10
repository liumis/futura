<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectoryContactPerson extends Model
{
    protected $table = 'directory_contact_persons';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'directory_contact_id',
        'name',
        'surname',
        'phone',
        'email',
        'comment',
    ];

    public function directoryContact(): BelongsTo
    {
        return $this->belongsTo(DirectoryContact::class);
    }

    public function fullName(): string
    {
        return trim("{$this->name} {$this->surname}");
    }
}
