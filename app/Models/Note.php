<?php

namespace App\Models;

use App\Enums\NoteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'body',
        'status',
        'user_id',
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
            'status' => NoteStatus::class,
            'attachments' => 'array',
            'sharepoint_files' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', NoteStatus::Active);
    }

    /**
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
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

    public function archive(): void
    {
        if ($this->status === NoteStatus::Archived) {
            return;
        }

        $this->update(['status' => NoteStatus::Archived]);
    }
}
