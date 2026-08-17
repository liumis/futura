<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\TodoComment;
use App\Services\ActivityLogger;
use App\Services\TodoNotificationMailer;

class TodoCommentObserver
{
    public function created(TodoComment $comment): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoCommentCreated,
            'Todo comment #'.$comment->id.' created for Todo #'.$comment->todo_id,
            $comment,
        );

        TodoNotificationMailer::notifyCommentAdded($comment);
    }

    public function updated(TodoComment $comment): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoCommentUpdated,
            'Todo comment #'.$comment->id.' updated for Todo #'.$comment->todo_id,
            $comment,
            ['changes' => $comment->getChanges()],
        );
    }

    public function deleted(TodoComment $comment): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoCommentDeleted,
            'Todo comment #'.$comment->id.' deleted for Todo #'.$comment->todo_id,
            null,
            ['deleted_todo_comment_id' => $comment->getKey(), 'todo_id' => $comment->todo_id],
        );
    }
}
