<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExpense extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'worked_on',
        'hours',
        'amount',
        'comment',
        'invoice_id',
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
            'worked_on' => 'date',
            'hours' => 'decimal:2',
            'amount' => 'decimal:2',
            'attachments' => 'array',
            'sharepoint_files' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function folderLabel(): string
    {
        $project = $this->relationLoaded('project') ? $this->project : $this->project()->first();
        $projectPart = $project?->code ?: ($project?->name ?: 'expense');
        $datePart = $this->worked_on?->format('Y-m-d') ?: 'no-date';

        return $projectPart.'-'.$datePart;
    }
}
