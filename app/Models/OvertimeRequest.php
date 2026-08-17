<?php

namespace App\Models;

use App\Enums\OvertimeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OvertimeRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'date',
        'hours',
        'overtime_request_type_id',
        'comment',
        'status',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'decimal:2',
            'status' => OvertimeRequestStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRequestType(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequestType::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function extraApprovers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'overtime_request_approvers')
            ->using(OvertimeRequestApprover::class)
            ->withPivot(['approved_at'])
            ->withTimestamps();
    }

    public function isConfirmed(): bool
    {
        return $this->status === OvertimeRequestStatus::Confirmed;
    }

    public function isEditable(): bool
    {
        return $this->status === OvertimeRequestStatus::New && blank($this->confirmed_at);
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
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
        if ($user === null || $this->status !== OvertimeRequestStatus::New || $this->confirmerHasConfirmed()) {
            return false;
        }

        return (int) ($this->confirmed_by ?? 0) === (int) $user->getKey();
    }

    public function isAwaitingExtraApprovalFrom(?User $user): bool
    {
        if ($user === null || $this->status !== OvertimeRequestStatus::New) {
            return false;
        }

        $this->loadMissing('extraApprovers');

        $approver = $this->extraApprovers->firstWhere('id', $user->getKey());

        return $approver !== null && blank($approver->pivot?->approved_at);
    }

    public function isAwaitingCancellationApprovalFrom(?User $user): bool
    {
        if ($user === null || $this->status !== OvertimeRequestStatus::CancellationPending) {
            return false;
        }

        return (int) ($this->confirmed_by ?? 0) === (int) $user->getKey();
    }

    public function canRequestCancellation(?User $user): bool
    {
        return $user !== null
            && $this->status === OvertimeRequestStatus::Confirmed
            && ($user->hasRole('admin') || (int) $user->getKey() === (int) ($this->confirmed_by ?? 0));
    }
}
