<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\CustomerLevelPrice;
use App\Services\ActivityLogger;

class CustomerLevelPriceObserver
{
    public function created(CustomerLevelPrice $customerLevelPrice): void
    {
        $customerLevelPrice->loadMissing(['customerLevel', 'collection']);

        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelPriceCreated,
            'Customer level price created: '.$this->summary($customerLevelPrice),
            $customerLevelPrice,
        );
    }

    public function updated(CustomerLevelPrice $customerLevelPrice): void
    {
        $customerLevelPrice->loadMissing(['customerLevel', 'collection']);

        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelPriceUpdated,
            'Customer level price updated: '.$this->summary($customerLevelPrice),
            $customerLevelPrice,
            ['changes' => $customerLevelPrice->getChanges()],
        );
    }

    public function deleted(CustomerLevelPrice $customerLevelPrice): void
    {
        $customerLevelPrice->loadMissing(['customerLevel', 'collection']);

        ActivityLogger::log(
            ActivityLogEvent::CustomerLevelPriceDeleted,
            'Customer level price deleted: '.$this->summary($customerLevelPrice),
            null,
            [
                'deleted_id' => $customerLevelPrice->getKey(),
                'customer_level_id' => $customerLevelPrice->customer_level_id,
                'collection_id' => $customerLevelPrice->collection_id,
            ],
        );
    }

    private function summary(CustomerLevelPrice $row): string
    {
        $level = $row->customerLevel?->name ?? '—';
        $coll = $row->collection?->name ?? '—';

        return "{$level} · {$coll} · \${$row->price}";
    }
}
