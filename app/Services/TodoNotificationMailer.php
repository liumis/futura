<?php

namespace App\Services;

use App\Enums\TodoStatus;
use App\Models\Todo;
use App\Models\TodoComment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TodoNotificationMailer
{
    public static function notifyStatusChanged(Todo $todo, mixed $oldStatus, mixed $newStatus): void
    {
        try {
            $todo->loadMissing(['watchers', 'responsible', 'user', 'project']);

            $oldLabel = self::statusLabel($oldStatus);
            $newLabel = self::statusLabel($newStatus);
            $newEnum = self::normalizeStatus($newStatus);

            if ($oldLabel === $newLabel) {
                return;
            }

            $actorId = auth()->id();
            $actorName = self::actorName();
            $taskTitle = $todo->displayTitle();
            $awaitingConfirm = $newEnum === TodoStatus::Confirm;

            $subject = $awaitingConfirm
                ? 'Task awaiting confirmation: '.$taskTitle
                : 'Task status changed: '.$taskTitle;

            $body = implode("\n", array_filter([
                'Task: '.$taskTitle,
                'Status changed: '.$oldLabel.' → '.$newLabel,
                $awaitingConfirm ? 'Please review and return the task or mark it done.' : null,
                $actorName !== null ? 'Changed by: '.$actorName : null,
                '',
                'Open the task in the admin panel to review details.',
            ], static fn ($line): bool => $line !== null));

            self::dispatch(
                $todo,
                $subject,
                $body,
                $actorId ? (int) $actorId : null,
                confirmOnlyResponsible: $awaitingConfirm,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function notifyCommentAdded(TodoComment $comment): void
    {
        try {
            $comment->loadMissing(['todo.watchers', 'todo.responsible', 'todo.user', 'todo.project', 'user']);

            $todo = $comment->todo;
            if ($todo === null) {
                return;
            }

            $actorId = $comment->user_id ? (int) $comment->user_id : (auth()->id() ? (int) auth()->id() : null);
            $authorName = trim((string) ($comment->user?->fullName() ?: $comment->user?->email ?: 'Someone'));
            $taskTitle = $todo->displayTitle();
            $content = trim((string) ($comment->content ?? ''));

            $subject = 'New comment on task: '.$taskTitle;
            $body = implode("\n", [
                'Task: '.$taskTitle,
                'Comment by: '.$authorName,
                '',
                $content !== '' ? $content : '(empty comment)',
            ]);

            self::dispatch($todo, $subject, $body, $actorId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Collection<int, User>|null  $recipients
     */
    protected static function dispatch(
        Todo $todo,
        string $subject,
        string $body,
        ?int $excludeUserId = null,
        bool $confirmOnlyResponsible = false,
    ): void {
        foreach (self::recipients($todo, $excludeUserId, $confirmOnlyResponsible) as $recipient) {
            self::sendTo($recipient, $subject, $body);
        }
    }

    /**
     * @return Collection<int, User>
     */
    protected static function recipients(
        Todo $todo,
        ?int $excludeUserId = null,
        bool $confirmOnlyResponsible = false,
    ): Collection {
        $todo->loadMissing(['watchers', 'responsible']);

        /** @var Collection<int, User> $users */
        $users = collect();

        $authorId = $todo->user_id ? (int) $todo->user_id : null;
        $responsibleId = $todo->responsible_id ? (int) $todo->responsible_id : null;
        $includeResponsible = $responsibleId !== null
            && $authorId !== null
            && $responsibleId !== $authorId
            && $todo->responsible instanceof User;

        if ($confirmOnlyResponsible) {
            if ($includeResponsible) {
                $users->push($todo->responsible);
            }
        } else {
            $users = collect($todo->watchers);

            if ($includeResponsible) {
                $users->push($todo->responsible);
            }
        }

        return $users
            ->filter(fn (User $user): bool => filled(trim((string) ($user->email ?? ''))))
            ->when(
                $excludeUserId !== null,
                fn (Collection $collection): Collection => $collection->reject(
                    fn (User $user): bool => (int) $user->getKey() === $excludeUserId
                )
            )
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values();
    }

    protected static function sendTo(User $recipient, string $subject, string $body): void
    {
        $email = trim((string) $recipient->email);
        if ($email === '') {
            return;
        }

        $from = self::fromAddress();

        if (EmailTestMode::isEnabled()) {
            EmailLogWriter::logManual(
                to: $email,
                subject: $subject,
                body: $body,
                from: $from['formatted'],
                bodyAppendix: EmailTestMode::blockedMessage(),
            );

            return;
        }

        Mail::raw($body, function ($message) use ($email, $subject, $from): void {
            $message->to($email)->subject($subject);
            $message->from($from['address'], $from['name']);
        });
    }

    /**
     * @return array{address: string, name: string, formatted: string}
     */
    protected static function fromAddress(): array
    {
        $user = auth()->user();

        if ($user instanceof User && filled($user->email)) {
            $name = $user->fullName();
            $name = $name !== '' ? $name : (string) $user->email;

            return [
                'address' => (string) $user->email,
                'name' => $name,
                'formatted' => sprintf('%s <%s>', $name, $user->email),
            ];
        }

        $address = (string) config('mail.from.address');
        $name = (string) (config('mail.from.name') ?: $address);

        return [
            'address' => $address,
            'name' => $name,
            'formatted' => sprintf('%s <%s>', $name, $address),
        ];
    }

    protected static function actorName(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $name = $user->fullName();

        return $name !== '' ? $name : (string) ($user->email ?? '');
    }

    protected static function statusLabel(mixed $status): string
    {
        $enum = self::normalizeStatus($status);

        return $enum?->getLabel() ?? (is_string($status) && $status !== '' ? $status : '—');
    }

    protected static function normalizeStatus(mixed $status): ?TodoStatus
    {
        if ($status instanceof TodoStatus) {
            return $status;
        }

        if (is_string($status) && $status !== '') {
            return TodoStatus::tryFrom($status);
        }

        return null;
    }
}
