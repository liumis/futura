<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSigningSigner extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_signing_id',
        'signer_key',
        'user_id',
        'name',
        'surname',
        'email',
        'is_external',
        'dokobit_access_token',
        'signing_url',
        'invited_at',
        'signed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'invited_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function signing(): BelongsTo
    {
        return $this->belongsTo(DocumentSigning::class, 'document_signing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        $full = trim(($this->name ?? '').' '.($this->surname ?? ''));

        if ($full !== '') {
            return $full;
        }

        return filled($this->email) ? (string) $this->email : 'Signer #'.$this->getKey();
    }

    public function hasSigned(): bool
    {
        return filled($this->signed_at);
    }
}
