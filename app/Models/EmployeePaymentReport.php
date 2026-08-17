<?php

namespace App\Models;

use App\Enums\EmployeePaymentReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePaymentReport extends Model
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
            'status' => EmployeePaymentReportStatus::class,
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
        return $this->hasMany(EmployeeMonthlyPayment::class);
    }

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_payment_report_approvers')
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
            $status = EmployeePaymentReportStatus::Created;
        } elseif ($pending) {
            $status = EmployeePaymentReportStatus::WaitingConfirmations;
        } else {
            $status = EmployeePaymentReportStatus::Confirmed;
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
