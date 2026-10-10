<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentConfirmation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'user_id',
        'cycle_index',
        'due_at',
        'notified_at',
        'confirmed_at',
        'confirmed_ip',
        'attachment_path',
        'attachment_original_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_index' => 'integer',
            'due_at' => 'date',
            'notified_at' => 'datetime',
            'confirmed_at' => 'datetime',
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

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isPending(): bool
    {
        return $this->confirmed_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isPending()
            && $this->due_at !== null
            && $this->due_at->copy()->startOfDay()->lt(now()->startOfDay());
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at');
    }

    public function scopeDueForNotification(Builder $query, ?CarbonInterface $onDate = null): Builder
    {
        $date = ($onDate ?? now())->toDateString();

        return $query
            ->whereNull('confirmed_at')
            ->whereNull('notified_at')
            ->whereDate('due_at', '<=', $date);
    }
}
