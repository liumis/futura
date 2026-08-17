<?php

namespace App\Models;

use App\Enums\TaskCalendarExternalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCalendarEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'todo_id',
        'calendar_connection_id',
        'external_event_id',
        'last_external_event_id',
        'external_change_key',
        'external_status',
        'last_external_modified_at',
        'last_synced_at',
        'deleted_externally_at',
        'sync_hash',
        'last_sync_origin',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_status' => TaskCalendarExternalStatus::class,
            'last_external_modified_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'deleted_externally_at' => 'datetime',
        ];
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    public function isDeletedExternally(): bool
    {
        return $this->external_status === TaskCalendarExternalStatus::DeletedExternally
            || $this->deleted_externally_at !== null;
    }

    public function canRestoreToOutlook(): bool
    {
        return $this->isDeletedExternally()
            || (blank($this->external_event_id) && filled($this->last_external_event_id));
    }
}
