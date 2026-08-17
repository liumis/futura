<?php

namespace App\Services;

use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\OvertimeRequestCancellationNotification;
use App\Notifications\OvertimeRequestConfirmationNotification;
use App\Notifications\OvertimeRequestExtraApprovalNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OvertimeRequestConfirmer
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
        $status = (string) ($data['status'] ?? OvertimeRequestStatus::New->value);

        if (in_array($status, [
            OvertimeRequestStatus::Canceled->value,
            OvertimeRequestStatus::CancellationPending->value,
            OvertimeRequestStatus::Confirmed->value,
        ], true)) {
            return $data;
        }

        if ($confirmerId > 0 && $actorId > 0 && $confirmerId === $actorId) {
            $data['confirmed_by'] = $actorId;
            $data['confirmed_at'] = now()->format('Y-m-d H:i:s');
            $data['status'] = OvertimeRequestStatus::New->value;

            return $data;
        }

        $data['status'] = OvertimeRequestStatus::New->value;
        $data['confirmed_at'] = null;

        return $data;
    }

    /**
     * @param  list<int>|null  $previousExtraApproverIds
     */
    public static function afterPersist(
        OvertimeRequest $overtime,
        bool $wasRecentlyCreated,
        ?int $previousConfirmerId = null,
        ?OvertimeRequestStatus $previousStatus = null,
        ?array $previousExtraApproverIds = null,
    ): void {
        self::autoApproveActorAsExtra($overtime);
        self::finalizeIfReady($overtime);

        $overtime->refresh();

        self::notifyIfNeeded(
            overtime: $overtime,
            wasRecentlyCreated: $wasRecentlyCreated,
            previousConfirmerId: $previousConfirmerId,
            previousStatus: $previousStatus,
        );

        self::notifyExtraApproversIfNeeded(
            overtime: $overtime,
            wasRecentlyCreated: $wasRecentlyCreated,
            previousExtraApproverIds: $previousExtraApproverIds,
        );
    }

    public static function notifyIfNeeded(
        OvertimeRequest $overtime,
        bool $wasRecentlyCreated,
        ?int $previousConfirmerId = null,
        ?OvertimeRequestStatus $previousStatus = null,
    ): void {
        if ($overtime->status !== OvertimeRequestStatus::New || $overtime->confirmerHasConfirmed()) {
            return;
        }

        $confirmerId = (int) ($overtime->confirmed_by ?? 0);
        $actorId = (int) (auth()->id() ?? 0);

        if ($confirmerId <= 0 || $confirmerId === $actorId) {
            return;
        }

        $confirmerChanged = $previousConfirmerId !== $confirmerId;
        $becameNew = $previousStatus !== null && $previousStatus !== OvertimeRequestStatus::New;

        if (! $wasRecentlyCreated && ! $confirmerChanged && ! $becameNew) {
            return;
        }

        self::notifyUser(
            $confirmerId,
            new OvertimeRequestConfirmationNotification(
                overtimeRequestId: (int) $overtime->getKey(),
                summary: self::summary($overtime),
                url: OvertimeRequestResource::getUrl('edit', ['record' => $overtime]),
            ),
            $overtime,
        );
    }

    /**
     * @param  list<int>|null  $previousExtraApproverIds
     */
    public static function notifyExtraApproversIfNeeded(
        OvertimeRequest $overtime,
        bool $wasRecentlyCreated,
        ?array $previousExtraApproverIds = null,
    ): void {
        if ($overtime->status !== OvertimeRequestStatus::New) {
            return;
        }

        $overtime->loadMissing('extraApprovers');
        $actorId = (int) (auth()->id() ?? 0);
        $previous = collect($previousExtraApproverIds ?? []);

        foreach ($overtime->extraApprovers as $approver) {
            $approverId = (int) $approver->getKey();

            if ($approverId === $actorId || filled($approver->pivot?->approved_at)) {
                continue;
            }

            if (! $wasRecentlyCreated && $previous->contains($approverId)) {
                continue;
            }

            self::notifyUser(
                $approverId,
                new OvertimeRequestExtraApprovalNotification(
                    overtimeRequestId: (int) $overtime->getKey(),
                    summary: self::summary($overtime),
                    url: OvertimeRequestResource::getUrl('edit', ['record' => $overtime]),
                ),
                $overtime,
            );
        }
    }

    public static function confirm(OvertimeRequest $overtime, User $user): bool
    {
        if ($overtime->status !== OvertimeRequestStatus::New || $overtime->confirmerHasConfirmed()) {
            return false;
        }

        if ((int) ($overtime->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $overtime->forceFill([
            'confirmed_by' => (int) ($overtime->confirmed_by ?? $user->getKey()),
            'confirmed_at' => now(),
        ])->save();

        self::markExtraApproved($overtime, $user);
        self::finalizeIfReady($overtime);

        return true;
    }

    public static function approveAsExtra(OvertimeRequest $overtime, User $user): bool
    {
        if (! $overtime->isAwaitingExtraApprovalFrom($user)) {
            return false;
        }

        self::markExtraApproved($overtime, $user);
        self::finalizeIfReady($overtime);

        return true;
    }

    public static function requestCancellation(OvertimeRequest $overtime, User $actor): bool
    {
        if (! $overtime->canRequestCancellation($actor)) {
            return false;
        }

        $overtime->forceFill([
            'status' => OvertimeRequestStatus::CancellationPending,
        ])->save();

        $confirmerId = (int) ($overtime->confirmed_by ?? 0);
        if ($confirmerId > 0 && $confirmerId !== (int) $actor->getKey()) {
            self::notifyUser(
                $confirmerId,
                new OvertimeRequestCancellationNotification(
                    overtimeRequestId: (int) $overtime->getKey(),
                    summary: self::summary($overtime),
                    url: OvertimeRequestResource::getUrl('edit', ['record' => $overtime]),
                ),
                $overtime,
            );
        }

        if ($confirmerId === (int) $actor->getKey()) {
            return self::approveCancellation($overtime, $actor);
        }

        return true;
    }

    public static function approveCancellation(OvertimeRequest $overtime, User $user): bool
    {
        if ($overtime->status !== OvertimeRequestStatus::CancellationPending) {
            return false;
        }

        if ((int) ($overtime->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $overtime->forceFill([
            'status' => OvertimeRequestStatus::Canceled,
        ])->save();

        return true;
    }

    public static function rejectCancellation(OvertimeRequest $overtime, User $user): bool
    {
        if ($overtime->status !== OvertimeRequestStatus::CancellationPending) {
            return false;
        }

        if ((int) ($overtime->confirmed_by ?? 0) !== (int) $user->getKey()
            && ! $user->hasRole('admin')) {
            return false;
        }

        $overtime->forceFill([
            'status' => OvertimeRequestStatus::Confirmed,
        ])->save();

        return true;
    }

    public static function finalizeIfReady(OvertimeRequest $overtime): void
    {
        $overtime->refresh();
        $overtime->load('extraApprovers');

        if ($overtime->status !== OvertimeRequestStatus::New) {
            return;
        }

        if (! $overtime->confirmerHasConfirmed() || ! $overtime->extraApprovalsComplete()) {
            return;
        }

        $overtime->forceFill([
            'status' => OvertimeRequestStatus::Confirmed,
        ])->save();
    }

    protected static function autoApproveActorAsExtra(OvertimeRequest $overtime): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        if ($overtime->isAwaitingExtraApprovalFrom($actor)) {
            self::markExtraApproved($overtime, $actor);
        }
    }

    protected static function markExtraApproved(OvertimeRequest $overtime, User $user): void
    {
        if (! $overtime->extraApprovers()->whereKey($user->getKey())->exists()) {
            return;
        }

        $overtime->extraApprovers()->updateExistingPivot($user->getKey(), [
            'approved_at' => now(),
        ]);
    }

    protected static function summary(OvertimeRequest $overtime): string
    {
        $overtime->loadMissing(['employee', 'overtimeRequestType']);

        $employeeName = $overtime->employee?->fullName() ?? 'Employee';
        $typeName = $overtime->overtimeRequestType?->name ?? 'Overtime';
        $date = $overtime->date?->toDateString() ?? '—';
        $hours = number_format((float) ($overtime->hours ?? 0), 2);

        return "{$employeeName}: {$typeName} ({$date}, {$hours}h)";
    }

    protected static function notifyUser(int $userId, object $notification, OvertimeRequest $overtime): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            Log::warning('Overtime request notification failed', [
                'overtime_request_id' => $overtime->getKey(),
                'user_id' => $user->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
