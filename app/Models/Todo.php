<?php

namespace App\Models;

use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Services\TodoStatusWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Todo extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'responsible_id',
        'project_id',
        'title',
        'description',
        'has_finances',
        'total_income',
        'income_left',
        'total_payment',
        'payment_left',
        'deadline',
        'start_date',
        'status',
        'priority',
        'archived',
        'calendar_sync_enabled',
        'all_day',
        'attachments',
        'sharepoint_web_url',
        'sharepoint_item_id',
        'sharepoint_path',
        'sharepoint_files',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'start_date' => 'datetime',
            'status' => TodoStatus::class,
            'priority' => TodoPriority::class,
            'archived' => 'boolean',
            'calendar_sync_enabled' => 'boolean',
            'all_day' => 'boolean',
            'attachments' => 'array',
            'sharepoint_files' => 'array',
            'has_finances' => 'boolean',
            'total_income' => 'decimal:2',
            'income_left' => 'decimal:2',
            'total_payment' => 'decimal:2',
            'payment_left' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Todo $todo): void {
            if ($todo->deadline === null) {
                $todo->deadline = now()->addDay()->setTime(18, 0);
            }

            if ($todo->responsible_id === null && $todo->user_id !== null) {
                $todo->responsible_id = $todo->user_id;
            }

            if ($todo->priority === null) {
                $todo->priority = TodoPriority::Regular;
            }
        });

        static::updating(function (Todo $todo): void {
            if (! $todo->isDirty('status')) {
                return;
            }

            $actor = auth()->user() instanceof User ? auth()->user() : null;

            TodoStatusWorkflow::assertCanTransition(
                $todo,
                $todo->getOriginal('status'),
                $todo->status,
                $actor,
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function displayTitle(): string
    {
        $code = (string) ($this->project?->code ?? '?');

        return sprintf('[%s][%s] %s', $code, $this->getKey(), $this->title);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'todo_watchers');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TodoComment::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(TaskCalendarEvent::class);
    }

    public function latestComment(): HasOne
    {
        return $this->hasOne(TodoComment::class)->latestOfMany();
    }

    /**
     * @param  Builder<Todo>  $query
     * @return Builder<Todo>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    public function hasSharepointFiles(): bool
    {
        return is_array($this->sharepoint_files) && $this->sharepoint_files !== [];
    }

    /**
     * @return list<array{name: string, url: ?string, item_id: ?string, path: ?string, uploaded_at: ?string}>
     */
    public function sharepointAttachmentLinks(): array
    {
        $links = [];

        if (is_array($this->sharepoint_files)) {
            foreach ($this->sharepoint_files as $file) {
                if (! is_array($file)) {
                    continue;
                }

                $name = filled($file['name'] ?? null)
                    ? (string) $file['name']
                    : (filled($file['path'] ?? null)
                        ? basename(str_replace('\\', '/', (string) $file['path']))
                        : 'file');

                $links[] = [
                    'name' => $name,
                    'url' => filled($file['web_url'] ?? null) ? (string) $file['web_url'] : null,
                    'item_id' => filled($file['item_id'] ?? null) ? (string) $file['item_id'] : null,
                    'path' => filled($file['path'] ?? null) ? (string) $file['path'] : null,
                    'uploaded_at' => filled($file['uploaded_at'] ?? null) ? (string) $file['uploaded_at'] : null,
                ];
            }
        }

        return $links;
    }
}
