<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LeaveRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'date_from',
        'date_to',
        'leave_request_type_id',
        'comment',
        'payment_gross',
        'status',
        'confirmed_by',
        'confirmed_at',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'payment_gross' => 'decimal:2',
            'status' => LeaveRequestStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveRequestType(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestType::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function extraApprovers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'leave_request_approvers')
            ->using(LeaveRequestApprover::class)
            ->withPivot(['approved_at'])
            ->withTimestamps();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === LeaveRequestStatus::Confirmed;
    }

    public function isEditable(): bool
    {
        return $this->status === LeaveRequestStatus::New && blank($this->confirmed_at);
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    public function allowsPastDates(): bool
    {
        return $this->isEditable();
    }

    public function confirmerHasConfirmed(): bool
    {
        return filled($this->confirmed_at);
    }

    public function extraApprovalsComplete(): bool
    {
        $this->loadMissing('extraApprovers');

        if ($this->extraApprovers->isEmpty()) {
            return true;
        }

        return $this->extraApprovers->every(
            fn (User $approver): bool => filled($approver->pivot?->approved_at),
        );
    }

    public function extraApprovalSummary(): string
    {
        $this->loadMissing('extraApprovers');

        if ($this->extraApprovers->isEmpty()) {
            return '—';
        }

        $approved = $this->extraApprovers
            ->filter(fn (User $approver): bool => filled($approver->pivot?->approved_at))
            ->count();

        return $approved.'/'.$this->extraApprovers->count();
    }

    public function isAwaitingConfirmationFrom(?User $user): bool
    {
        if ($user === null || $this->status !== LeaveRequestStatus::New || $this->confirmerHasConfirmed()) {
            return false;
        }

        return (int) ($this->confirmed_by ?? 0) === (int) $user->getKey();
    }

    public function isAwaitingExtraApprovalFrom(?User $user): bool
    {
        if ($user === null || $this->status !== LeaveRequestStatus::New) {
            return false;
        }

        $this->loadMissing('extraApprovers');

        $approver = $this->extraApprovers->firstWhere('id', $user->getKey());

        return $approver !== null && blank($approver->pivot?->approved_at);
    }

    public function isAwaitingCancellationApprovalFrom(?User $user): bool
    {
        if ($user === null || $this->status !== LeaveRequestStatus::CancellationPending) {
            return false;
        }

        return (int) ($this->confirmed_by ?? 0) === (int) $user->getKey();
    }

    public function canRequestCancellation(?User $user): bool
    {
        return $user !== null
            && $this->status === LeaveRequestStatus::Confirmed
            && ($user->hasRole('admin') || (int) $user->getKey() === (int) ($this->confirmed_by ?? 0));
    }
}
