<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_key',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function subjectLabel(): ?string
    {
        $subject = $this->subject;
        if ($subject === null) {
            $props = $this->properties ?? [];
            if (filled($props['invoice_number'] ?? null)) {
                return (string) $props['invoice_number'];
            }
            if (filled($props['invoice_id'] ?? null)) {
                return 'Invoice #'.$props['invoice_id'];
            }

            return null;
        }

        if (method_exists($subject, 'displayNumber')) {
            return (string) $subject->displayNumber();
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
