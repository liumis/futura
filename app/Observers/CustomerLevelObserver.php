<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\CustomerLevel;
use App\Services\ActivityLogger;

class CustomerLevelObserver
{
    public function created(CustomerLevel $customerLevel): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelCreated,
            "Customer level \"{$customerLevel->name}\" created (#{$customerLevel->id})",
            $customerLevel,
        );
    }

    public function updated(CustomerLevel $customerLevel): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelUpdated,
            "Customer level \"{$customerLevel->name}\" updated (#{$customerLevel->id})",
            $customerLevel,
            ['changes' => $customerLevel->getChanges()],
        );
    }

    public function deleted(CustomerLevel $customerLevel): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelDeleted,
            "Customer level \"{$customerLevel->name}\" deleted (#{$customerLevel->id})",
            null,
            ['deleted_customer_level_id' => $customerLevel->getKey()],
        );
    }
}
