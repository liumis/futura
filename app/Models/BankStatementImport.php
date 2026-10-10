<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'original_filename',
        'stored_path',
        'content_hash',
        'bank_name',
        'account_iban',
        'account_currency',
        'statement_id',
        'message_id',
        'comment',
        'period_from',
        'period_to',
        'uploaded_by',
        'transaction_count',
        'imported_count',
        'skipped_count',
        'sharepoint_web_url',
        'sharepoint_item_id',
        'sharepoint_path',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'transaction_count' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function uploadedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class);
    }

    public function hasSharepointLink(): bool
    {
        return filled($this->sharepoint_web_url) || filled($this->sharepoint_item_id);
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->original_filename ?: ''));
        if ($name !== '') {
            return $name;
        }

        $path = trim((string) ($this->sharepoint_path ?: ''));
        if ($path !== '') {
            return basename(str_replace('\\', '/', $path));
        }

        return 'Bank statement';
    }

    public function primarySharepointUrl(): ?string
    {
        $url = trim((string) ($this->sharepoint_web_url ?: ''));
        if ($url === '' || self::isLocalAppUrl($url)) {
            return null;
        }

        if (preg_match('#^https://#i', $url) === 1) {
            return $url;
        }

        $site = trim((string) (SharepointSetting::instance()->site_url ?: ''));
        if ($site !== '' && preg_match('#^https://#i', $site) === 1 && ! self::isLocalAppUrl($site)) {
            return rtrim($site, '/').'/'.ltrim($url, '/');
        }

        return null;
    }

    public function openDocumentLinksHtml(
        string $linkClass = 'text-primary-600 hover:underline dark:text-primary-400',
    ): string {
        $label = e($this->displayName());
        $url = $this->primarySharepointUrl();

        if ($url === null) {
            return $label;
        }

        return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="'.$linkClass.'" title="Open on SharePoint">'.$label.'</a>';
    }

    private static function isLocalAppUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_contains($path, '/bank-statement-imports/')) {
            return true;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }

        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost')
            || ($appHost !== '' && $host === $appHost && ! str_contains($host, 'sharepoint'));
    }
}
