<?php

namespace App\Models;

use App\Enums\DividendPaymentReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DividendPaymentReport extends Model
{
    protected $fillable = [
        'name',
        'status',
        'document_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DividendPaymentReportStatus::class,
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dividend_payment_report_approvers')
            ->withPivot(['approved_at', 'is_auto_approved'])
            ->withTimestamps();
    }

    public function pendingApprovers(): BelongsToMany
    {
        return $this->approvers()->wherePivotNull('approved_at');
    }

    public function refreshStatus(): void
    {
        $this->loadMissing('approvers');

        $hasApprovers = $this->approvers->isNotEmpty();
        $pending = $this->approvers->contains(
            fn (User $user): bool => blank($user->pivot?->approved_at),
        );

        if (! $hasApprovers) {
            $status = DividendPaymentReportStatus::Created;
        } elseif ($pending) {
            $status = DividendPaymentReportStatus::WaitingConfirmations;
        } else {
            $status = DividendPaymentReportStatus::Confirmed;
        }

        if ($this->status !== $status) {
            $this->update(['status' => $status]);
        }
    }

    public function userHasPendingApproval(int $userId): bool
    {
        return $this->approvers()
            ->where('users.id', $userId)
            ->wherePivotNull('approved_at')
            ->exists();
    }
}

