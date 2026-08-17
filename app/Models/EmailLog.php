<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'body',
        'mailer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from' => 'array',
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<int, string>|null  $addresses
     */
    public static function formatAddressList(?array $addresses): string
    {
        if (blank($addresses)) {
            return '—';
        }

        return implode(', ', $addresses);
    }
}
