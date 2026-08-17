<?php

namespace App\Models;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarConnection extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'external_account_id',
        'account_email',
        'calendar_id',
        'calendar_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'subscription_id',
        'subscription_expires_at',
        'subscription_client_state',
        'delta_link',
        'last_synced_at',
        'status',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CalendarProvider::class,
            'status' => CalendarConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taskEvents(): HasMany
    {
        return $this->hasMany(TaskCalendarEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === CalendarConnectionStatus::Active
            && filled($this->access_token)
            && filled($this->calendar_id);
    }

    public function needsTokenRefresh(): bool
    {
        if ($this->token_expires_at === null) {
            return true;
        }

        return $this->token_expires_at->lte(now()->addMinutes(2));
    }

    public function subscriptionNeedsRenewal(): bool
    {
        if (! filled($this->subscription_id) || $this->subscription_expires_at === null) {
            return true;
        }

        return $this->subscription_expires_at->lte(now()->addHours(12));
    }
}
