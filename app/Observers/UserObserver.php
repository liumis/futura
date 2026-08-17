<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\User;
use App\Services\ActivityLogger;

class UserObserver
{
    public function created(User $user): void
    {
        ActivityLogger::log(
            ActivityLogEvent::UserCreated,
            'User created: '.$user->email,
            $user,
        );
    }

    public function updated(User $user): void
    {
        ActivityLogger::log(
            ActivityLogEvent::UserUpdated,
            'User updated: '.$user->email,
            $user,
            ['changes' => $user->getChanges()],
        );
    }

    public function deleted(User $user): void
    {
        ActivityLogger::log(
            ActivityLogEvent::UserDeleted,
            'User deleted: '.$user->email,
            null,
            ['deleted_user_id' => $user->getKey()],
        );
    }
}
