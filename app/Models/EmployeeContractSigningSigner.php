<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeContractSigningSigner extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_contract_signing_id',
        'signer_key',
        'user_id',
        'employee_id',
        'name',
        'surname',
        'email',
        'dokobit_access_token',
        'signing_url',
        'signed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function signing(): BelongsTo
    {
        return $this->belongsTo(EmployeeContractSigning::class, 'employee_contract_signing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
