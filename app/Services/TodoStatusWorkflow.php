<?php

namespace App\Services;

use App\Enums\TodoStatus;
use App\Models\Todo;
use App\Models\User;

class TodoStatusWorkflow
{
    public static function isSelfManaged(Todo $todo): bool
    {
        $authorId = $todo->user_id ? (int) $todo->user_id : null;
        $responsibleId = $todo->responsible_id ? (int) $todo->responsible_id : null;

        return $authorId !== null
            && $responsibleId !== null
            && $authorId === $responsibleId;
    }

    public static function normalize(mixed $status): ?TodoStatus
    {
        if ($status instanceof TodoStatus) {
            return $status;
        }

        if (is_string($status) && $status !== '') {
            return TodoStatus::tryFrom($status);
        }

        return null;
    }

    public static function stepForward(Todo $todo, mixed $from, ?User $actor = null): ?TodoStatus
    {
        $fromStatus = self::normalize($from) ?? TodoStatus::New;
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        if (self::isSelfManaged($todo)) {
            return match ($fromStatus) {
                TodoStatus::New => TodoStatus::InProgress,
                TodoStatus::InProgress => TodoStatus::Done,
                TodoStatus::Confirm => TodoStatus::Done,
                TodoStatus::Returned => TodoStatus::InProgress,
                TodoStatus::Done => null,
            };
        }

        return match ($fromStatus) {
            TodoStatus::New => TodoStatus::InProgress,
            TodoStatus::InProgress => TodoStatus::Confirm,
            TodoStatus::Confirm => self::actorIsResponsible($todo, $actor) ? TodoStatus::Done : null,
            TodoStatus::Returned => TodoStatus::InProgress,
            TodoStatus::Done => null,
        };
    }

    public static function stepBack(Todo $todo, mixed $from, ?User $actor = null): ?TodoStatus
    {
        $fromStatus = self::normalize($from) ?? TodoStatus::New;
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        if (self::isSelfManaged($todo)) {
            return match ($fromStatus) {
                TodoStatus::New => null,
                TodoStatus::InProgress => TodoStatus::New,
                TodoStatus::Done => TodoStatus::InProgress,
                TodoStatus::Confirm => TodoStatus::InProgress,
                TodoStatus::Returned => null,
            };
        }

        return match ($fromStatus) {
            TodoStatus::New => null,
            TodoStatus::InProgress => TodoStatus::New,
            TodoStatus::Confirm => self::actorIsResponsible($todo, $actor) ? TodoStatus::Returned : null,
            TodoStatus::Returned => self::actorIsResponsible($todo, $actor) ? TodoStatus::Confirm : null,
            TodoStatus::Done => TodoStatus::Confirm,
        };
    }

    /**
     * @return list<TodoStatus>
     */
    public static function allowedNextStatuses(Todo $todo, TodoStatus $from, ?User $actor = null): array
    {
        return array_values(array_filter([
            self::stepForward($todo, $from, $actor),
            self::stepBack($todo, $from, $actor),
        ]));
    }

    public static function canTransition(Todo $todo, mixed $from, mixed $to, ?User $actor = null): bool
    {
        $fromStatus = self::normalize($from) ?? TodoStatus::New;
        $toStatus = self::normalize($to);

        if ($toStatus === null) {
            return false;
        }

        if ($fromStatus === $toStatus) {
            return true;
        }

        return in_array(
            $toStatus,
            self::allowedNextStatuses($todo, $fromStatus, $actor),
            true,
        );
    }

    public static function assertCanTransition(Todo $todo, mixed $from, mixed $to, ?User $actor = null): void
    {
        if (self::canTransition($todo, $from, $to, $actor)) {
            return;
        }

        $fromStatus = self::normalize($from) ?? TodoStatus::New;
        $toStatus = self::normalize($to);
        $toLabel = $toStatus?->getLabel() ?? (string) $to;

        if (
            ! self::isSelfManaged($todo)
            && $fromStatus === TodoStatus::Confirm
            && in_array($toStatus, [TodoStatus::Returned, TodoStatus::Done], true)
            && ! self::actorIsResponsible($todo, $actor)
        ) {
            throw new \RuntimeException(
                'Only the responsible person can return this task or mark it done.'
            );
        }

        if (self::isSelfManaged($todo)) {
            throw new \RuntimeException(
                'Self-assigned tasks follow: New → In progress → Done. Cannot move to '.$toLabel.'.'
            );
        }

        throw new \RuntimeException(
            'Delegated tasks follow: New → In progress → Confirm → Returned/Done. Cannot move to '.$toLabel.'.'
        );
    }

    /**
     * @return array<string, string>
     */
    public static function optionsFor(Todo $todo, ?User $actor = null): array
    {
        $current = self::normalize($todo->status) ?? TodoStatus::New;
        $statuses = [$current, ...self::allowedNextStatuses($todo, $current, $actor)];

        $options = [];
        foreach ($statuses as $status) {
            $options[$status->value] = (string) ($status->getLabel() ?? $status->value);
        }

        return $options;
    }

    public static function proxyFromForm(callable $get, ?Todo $record = null): Todo
    {
        return new Todo([
            'user_id' => $get('user_id') ?? $record?->user_id ?? auth()->id(),
            'responsible_id' => $get('responsible_id') ?? $record?->responsible_id ?? auth()->id(),
            'status' => $get('status') ?? $record?->status ?? TodoStatus::New,
        ]);
    }

    protected static function actorIsResponsible(Todo $todo, ?User $actor): bool
    {
        if ($actor === null || $todo->responsible_id === null) {
            return false;
        }

        return (int) $actor->getKey() === (int) $todo->responsible_id;
    }
}
