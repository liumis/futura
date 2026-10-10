<?php

namespace App\Models;

use App\Enums\DirectoryContactPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DirectoryContact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'type',
        'country',
        'segment',
        'priority',
        'website',
        'email',
        'phone',
        'directory_contact_stage_id',
        'last_contact',
        'next_contact',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => DirectoryContactPriority::class,
            'last_contact' => 'date',
            'next_contact' => 'date',
        ];
    }

    public function contactPersons(): HasMany
    {
        return $this->hasMany(DirectoryContactPerson::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DirectoryContactStage::class, 'directory_contact_stage_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DirectoryContactComment::class);
    }

    public function latestComment(): HasOne
    {
        return $this->hasOne(DirectoryContactComment::class)->latestOfMany('commented_at');
    }
}
