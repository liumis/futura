<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DocumentApprover extends Pivot
{
    protected $table = 'document_approvers';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'user_id',
        'approved_at',
        'is_auto_approved',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'is_auto_approved' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
