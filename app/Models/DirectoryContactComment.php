<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectoryContactComment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'directory_contact_id',
        'user_id',
        'commented_at',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commented_at' => 'datetime',
        ];
    }

    public function directoryContact(): BelongsTo
    {
        return $this->belongsTo(DirectoryContact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
