<?php

namespace App\Models;

use App\Enums\EmployeeContractSigningStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeContractSigning extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_contract_id',
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
            'status' => EmployeeContractSigningStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class, 'employee_contract_id');
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
        return $this->hasMany(EmployeeContractSigningSigner::class);
    }

    public function pendingSigners(): HasMany
    {
        return $this->signers()->whereNull('signed_at');
    }

    public function isPending(): bool
    {
        return $this->status === EmployeeContractSigningStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === EmployeeContractSigningStatus::Completed;
    }

    /**
     * @return list<string>
     */
    public function pendingSignerLabels(): array
    {
        return $this->signers
            ->filter(fn (EmployeeContractSigningSigner $signer): bool => blank($signer->signed_at))
            ->map(fn (EmployeeContractSigningSigner $signer): string => $signer->displayName())
            ->values()
            ->all();
    }

    public function signerForUser(?User $user): ?EmployeeContractSigningSigner
    {
        if ($user === null) {
            return null;
        }

        return $this->signers->first(
            fn (EmployeeContractSigningSigner $signer): bool => (int) $signer->user_id === (int) $user->getKey(),
        );
    }

    public function firstPendingSigningUrl(): ?string
    {
        $signer = $this->signers
            ->filter(fn (EmployeeContractSigningSigner $signer): bool => blank($signer->signed_at) && filled($signer->signing_url))
            ->first();

        return $signer?->signing_url;
    }
}
