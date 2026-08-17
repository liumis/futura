<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestCancellationNotification;
use App\Notifications\LeaveRequestConfirmationNotification;
use App\Notifications\LeaveRequestExtraApprovalNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LeaveRequestConfirmer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormData(array $data): array
    {
        unset($data['extraApprovers']);

        $confirmerId = (int) ($data['confirmed_by'] ?? 0);
        $actorId = (int) (auth()->id() ?? 0);
        $status = (string) ($data['status'] ?? LeaveRequestStatus::New->value);

        if (in_array($status, [
            LeaveRequestStatus::Canceled->value,
            LeaveRequestStatus::CancellationPending->value,
            LeaveRequestStatus::Confirmed->value,
        ], true)) {
            return $data;
        }

        if ($confirmerId > 0 && $actorId > 0 && $confirmerId === $actorId) {
            $data['confirmed_by'] = $actorId;
            $data['confirmed_at'] = now()->format('Y-m-d H:i:s');
            $data['status'] = LeaveRequestStatus::New->value;

            return $data;
        }

        $data['status'] = LeaveRequestStatus::New->value;
        $data['confirmed_at'] = null;

        return $data;
    }

    /**
     * @param  list<int>|null  $previousExtraApproverIds
     */
    public static function afterPersist(
        LeaveRequest $leave,
        bool $wasRecentlyCreated,
        ?int $previousConfirmerId = null,
        ?LeaveRequestStatus $previousStatus = null,
        ?array $previousExtraApproverIds = null,
    ): void {
        self::autoApproveActorAsExtra($leave);
        self::finalizeIfReady($leave);

        $leave->refresh();

        self::notifyIfNeeded(
            leave: $leave,
            wasRecentlyCreated: $wasRecentlyCreated,
            previousConfirmerId: $previousConfirmerId,
            previousStatus: $previousStatus,
        );

        self::notifyExtraApproversIfNeeded(
            leave: $leave,
            wasRecentlyCreated: $wasRecentlyCreated,
            previousExtraApproverIds: $previousExtraApproverIds,
        );
    }

    public static function notifyIfNeeded(
        LeaveRequest $leave,
        bool $wasRecentlyCreated,
        ?int $previousConfirmerId = null,
        ?LeaveRequestStatus $previousStatus = null,
    ): void {
        if ($leave->status !== LeaveRequestStatus::New || $leave->confirmerHasConfirmed()) {
            return;
        }

        $confirmerId = (int) ($leave->confirmed_by ?? 0);
        $actorId = (int) (auth()->id() ?? 0);

        if ($confirmerId <= 0 || $confirmerId === $actorId) {
            return;
        }

        $confirmerChanged = $previousConfirmerId !== $confirmerId;
        $becameNew = $previousStatus !== null && $previousStatus !== LeaveRequestStatus::New;

        if (! $wasRecentlyCreated && ! $confirmerChanged && ! $becameNew) {
            return;
        }

        self::notifyUser(
            $confirmerId,
            new LeaveRequestConfirmationNotification(
                leaveRequestId: (int) $leave->getKey(),
                summary: self::summary($leave),
                url: LeaveRequestResource::getUrl('edit', ['record' => $leave]),
            ),
            $leave,
        );
    }

    /**
     * @param  list<int>|null  $previousExtraApproverIds
     */
    public static function notifyExtraApproversIfNeeded(
        LeaveRequest $leave,
        bool $wasRecentlyCreated,
        ?array $previousExtraApproverIds = null,
    ): void {
        if ($leave->status !== LeaveRequestStatus::New) {
            return;
        }

        $leave->loadMissing('extraApprovers');
        $actorId = (int) (auth()->id() ?? 0);
        $previous = collect($previousExtraApproverIds ?? []);

        foreach ($leave->extraApprovers as $approver) {
            $approverId = (int) $approver->getKey();

            if ($approverId === $actorId || filled($approver->pivot?->approved_at)) {
                continue;
            }

            if (! $wasRecentlyCreated && $previous->contains($approverId)) {
                continue;
            }

            self::notifyUser(
                $approverId,
                new LeaveRequestExtraApprovalNotification(
                    leaveRequestId: (int) $leave->getKey(),
                    summary: self::summary($leave),
                    url: LeaveRequestResource::getUrl('edit', ['record' => $leave]),
                ),
                $leave,
            );
        }
    }

    public static function confirm(LeaveRequest $leave, User $user): bool
    {
        if ($leave->status !== LeaveRequestStatus::New || $leave->confirmerHasConfirmed()) {
            return false;
        }

        if ((int) ($leave->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $leave->forceFill([
            'confirmed_by' => (int) ($leave->confirmed_by ?? $user->getKey()),
            'confirmed_at' => now(),
        ])->save();

        self::markExtraApproved($leave, $user);
        self::finalizeIfReady($leave);

        return true;
    }

    public static function approveAsExtra(LeaveRequest $leave, User $user): bool
    {
        if (! $leave->isAwaitingExtraApprovalFrom($user)) {
            return false;
        }

        self::markExtraApproved($leave, $user);
        self::finalizeIfReady($leave);

        return true;
    }

    public static function requestCancellation(LeaveRequest $leave, User $actor): bool
    {
        if (! $leave->canRequestCancellation($actor)) {
            return false;
        }

        $leave->forceFill([
            'status' => LeaveRequestStatus::CancellationPending,
        ])->save();

        $confirmerId = (int) ($leave->confirmed_by ?? 0);
        if ($confirmerId > 0 && $confirmerId !== (int) $actor->getKey()) {
            self::notifyUser(
                $confirmerId,
                new LeaveRequestCancellationNotification(
                    leaveRequestId: (int) $leave->getKey(),
                    summary: self::summary($leave),
                    url: LeaveRequestResource::getUrl('edit', ['record' => $leave]),
                ),
                $leave,
            );
        }

        if ($confirmerId === (int) $actor->getKey()) {
            return self::approveCancellation($leave, $actor);
        }

        return true;
    }

    public static function approveCancellation(LeaveRequest $leave, User $user): bool
    {
        if ($leave->status !== LeaveRequestStatus::CancellationPending) {
            return false;
        }

        if ((int) ($leave->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $leave->forceFill([
            'status' => LeaveRequestStatus::Canceled,
        ])->save();

        return true;
    }

    public static function rejectCancellation(LeaveRequest $leave, User $user): bool
    {
        if ($leave->status !== LeaveRequestStatus::CancellationPending) {
            return false;
        }

        if ((int) ($leave->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $leave->forceFill([
            'status' => LeaveRequestStatus::Confirmed,
        ])->save();

        return true;
    }

    public static function finalizeIfReady(LeaveRequest $leave): void
    {
        $leave->refresh();
        $leave->load('extraApprovers');

        if ($leave->status !== LeaveRequestStatus::New) {
            return;
        }

        if (! $leave->confirmerHasConfirmed() || ! $leave->extraApprovalsComplete()) {
            return;
        }

        $leave->forceFill([
            'status' => LeaveRequestStatus::Confirmed,
        ])->save();
    }

    protected static function autoApproveActorAsExtra(LeaveRequest $leave): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        if ($leave->isAwaitingExtraApprovalFrom($actor)) {
            self::markExtraApproved($leave, $actor);
        }
    }

    protected static function markExtraApproved(LeaveRequest $leave, User $user): void
    {
        if (! $leave->extraApprovers()->whereKey($user->getKey())->exists()) {
            return;
        }

        $leave->extraApprovers()->updateExistingPivot($user->getKey(), [
            'approved_at' => now(),
        ]);
    }

    protected static function summary(LeaveRequest $leave): string
    {
        $leave->loadMissing(['employee', 'leaveRequestType']);

        $employeeName = $leave->employee?->fullName() ?? 'Employee';
        $typeName = $leave->leaveRequestType?->name ?? 'Leave';
        $from = $leave->date_from?->toDateString() ?? '—';
        $to = $leave->date_to?->toDateString() ?? '—';

        return "{$employeeName}: {$typeName} ({$from} → {$to})";
    }

    protected static function notifyUser(int $userId, object $notification, LeaveRequest $leave): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            Log::warning('Leave request notification failed', [
                'leave_request_id' => $leave->getKey(),
                'user_id' => $user->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
