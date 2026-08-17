<?php

namespace App\Observers;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic CRUD auditor for models registered in AppServiceProvider.
 */
class ModelActivityObserver
{
    public function created(Model $model): void
    {
        ActivityLogger::logModelEvent($model, 'created');
    }

    public function updated(Model $model): void
    {
        ActivityLogger::logModelEvent($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        ActivityLogger::logModelEvent($model, 'deleted');
    }
}
