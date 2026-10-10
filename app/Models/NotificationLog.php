<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'recipient_user_id',
        'channel',
        'notification_type',
        'title',
        'body',
        'url',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'database' => 'In-app',
            'mail' => 'Email',
            default => ucfirst((string) $this->channel),
        };
    }

    public function typeLabel(): string
    {
        return match ($this->notification_type) {
            'document_confirmation' => 'Document confirmation',
            'document_expiration' => 'Document expiration',
            default => str($this->notification_type)->headline()->toString(),
        };
    }
}
