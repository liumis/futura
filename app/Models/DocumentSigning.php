<?php

namespace App\Models;

use App\Enums\DocumentSigningStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSigning extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'name',
        'status',
        'dokobit_token',
        'dokobit_file_token',
        'created_by',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentSigningStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(DocumentSigningSigner::class);
    }

    public function pendingSigners(): HasMany
    {
        return $this->signers()->whereNull('signed_at');
    }

    public function isPending(): bool
    {
        return $this->status === DocumentSigningStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === DocumentSigningStatus::Completed;
    }

    /**
     * @return list<string>
     */
    public function pendingSignerLabels(): array
    {
        return $this->signers
            ->filter(fn (DocumentSigningSigner $signer): bool => blank($signer->signed_at))
            ->map(fn (DocumentSigningSigner $signer): string => $signer->displayName())
            ->values()
            ->all();
    }

    public function signerForUser(?User $user): ?DocumentSigningSigner
    {
        if ($user === null) {
            return null;
        }

        return $this->signers->first(
            fn (DocumentSigningSigner $signer): bool => (int) $signer->user_id === (int) $user->getKey(),
        );
    }

    public function firstPendingSigningUrl(): ?string
    {
        // Prefer internal pending URLs so callers never pick an external invite link by accident.
        $signer = $this->signers
            ->filter(fn (DocumentSigningSigner $signer): bool => ! $signer->is_external
                && blank($signer->signed_at)
                && filled($signer->signing_url))
            ->first()
            ?? $this->signers
                ->filter(fn (DocumentSigningSigner $signer): bool => blank($signer->signed_at) && filled($signer->signing_url))
                ->first();

        return $signer?->signing_url;
    }
}
