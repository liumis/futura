<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Cargo;
use App\Services\ActivityLogger;

class CargoObserver
{
    public function created(Cargo $cargo): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CargoCreated,
            "Cargo #{$cargo->id} created",
            $cargo,
        );
    }

    public function updated(Cargo $cargo): void
    {
        if ($cargo->wasChanged('status')) {
            ActivityLogger::log(
                ActivityLogEvent::CargoUpdated,
                'Cargo #'.$cargo->id.' status changed: '.$this->formatScalar($cargo->getOriginal('status')).' → '.$this->formatScalar($cargo->status),
                $cargo,
                ['old' => $cargo->getOriginal('status'), 'new' => $cargo->status],
            );
        }

        if ($cargo->wasChanged('tracking')) {
            ActivityLogger::log(
                ActivityLogEvent::CargoUpdated,
                'Cargo #'.$cargo->id.' tracking changed',
                $cargo,
                ['old' => $cargo->getOriginal('tracking'), 'new' => $cargo->tracking],
            );
        }

        if ($cargo->wasChanged('date_shipped')) {
            ActivityLogger::log(
                ActivityLogEvent::CargoUpdated,
                'Cargo #'.$cargo->id.' shipped date changed',
                $cargo,
                ['old' => $cargo->getOriginal('date_shipped'), 'new' => $cargo->date_shipped?->toDateString()],
            );
        }

        if ($cargo->wasChanged('estimated_arrival')) {
            ActivityLogger::log(
                ActivityLogEvent::CargoUpdated,
                'Cargo #'.$cargo->id.' estimated arrival changed',
                $cargo,
                ['old' => $cargo->getOriginal('estimated_arrival'), 'new' => $cargo->estimated_arrival?->toDateString()],
            );
        }

        $handled = ['status', 'tracking', 'date_shipped', 'estimated_arrival', 'updated_at', 'created_at'];
        $changedKeys = array_keys($cargo->getChanges());
        $other = array_values(array_diff($changedKeys, $handled));
        if ($other !== []) {
            ActivityLogger::log(
                ActivityLogEvent::CargoUpdated,
                'Cargo #'.$cargo->id.' updated',
                $cargo,
                ['attributes' => $cargo->only($other)],
            );
        }
    }

    public function deleted(Cargo $cargo): void
    {
        ActivityLogger::log(
            ActivityLogEvent::CargoDeleted,
            "Cargo #{$cargo->id} deleted",
            null,
            ['deleted_cargo_id' => $cargo->getKey()],
        );
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
