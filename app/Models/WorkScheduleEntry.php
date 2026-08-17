<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_schedule_id',
        'work_date',
        'hours',
        'actual_hours',
        'is_not_working_day',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'is_not_working_day' => 'boolean',
        ];
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }
}
