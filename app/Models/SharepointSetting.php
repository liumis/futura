<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharepointSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'is_enabled',
        'tenant_id',
        'client_id',
        'client_secret',
        'site_url',
        'site_id',
        'drive_id',
        'document_library',
        'root_folder_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public static function instance(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function isConfigured(): bool
    {
        return filled($this->tenant_id)
            && filled($this->client_id)
            && filled($this->client_secret)
            && (filled($this->site_url) || filled($this->site_id));
    }

    public function isReady(): bool
    {
        return $this->is_enabled && $this->isConfigured();
    }
}
