<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Collection;
use App\Services\ActivityLogger;

class CollectionObserver
{
    public function created(Collection $collection): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CollectionCreated,
            "Collection \"{$collection->name}\" created (#{$collection->id})",
            $collection,
        );
    }

    public function updated(Collection $collection): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CollectionUpdated,
            "Collection \"{$collection->name}\" updated (#{$collection->id})",
            $collection,
            ['changes' => $collection->getChanges()],
        );
    }

    public function deleted(Collection $collection): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CollectionDeleted,
            "Collection \"{$collection->name}\" deleted (#{$collection->id})",
            null,
            ['deleted_collection_id' => $collection->getKey()],
        );
    }
}
