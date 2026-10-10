<?php

namespace App\Models;

use App\Enums\EmployeeNpdType;
use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'birthdate',
        'position',
        'bank_account',
        'contract_signed_date',
        'contract_end_date',
        'phone',
        'email',
        'status',
        'user_id',
        'working_time_percentage',
        'shareholder_percentage',
        'is_general_manager',
        'npd_type',
        'second_pillar_enrolled',
        'second_pillar_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'contract_signed_date' => 'date',
            'contract_end_date' => 'date',
            'status' => EmployeeStatus::class,
            'working_time_percentage' => 'decimal:2',
            'shareholder_percentage' => 'decimal:2',
            'is_general_manager' => 'boolean',
            'npd_type' => EmployeeNpdType::class,
            'second_pillar_enrolled' => 'boolean',
            'second_pillar_rate' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?? EmployeeStatus::Active) === EmployeeStatus::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Active);
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->surname);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function businessTripRequests(): HasMany
    {
        return $this->hasMany(BusinessTripRequest::class);
    }

    public function employeeContracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function latestTerminatedContract(): ?EmployeeContract
    {
        $this->loadMissing(['employeeContracts.terminationDocument']);

        return $this->employeeContracts
            ->filter(fn (EmployeeContract $contract): bool => $contract->isTerminated())
            ->sortByDesc(fn (EmployeeContract $contract): string => sprintf(
                '%s-%020d',
                $contract->termination_date?->toDateString() ?? '0000-00-00',
                (int) $contract->getKey(),
            ))
            ->first();
    }

    public function displayedTerminationDate(): ?string
    {
        return $this->latestTerminatedContract()?->termination_date?->toDateString()
            ?? $this->contract_end_date?->toDateString();
    }

    public function displayedTerminationDocument(): ?Document
    {
        return $this->latestTerminatedContract()?->terminationDocument;
    }

    public function displayedTerminationDocumentUrl(): ?string
    {
        $document = $this->displayedTerminationDocument();
        if ($document === null) {
            return null;
        }

        foreach ($document->attachedFileLinks() as $file) {
            if (filled($file['url'] ?? null)) {
                return (string) $file['url'];
            }
        }

        return $document->displayFileUrl();
    }

    public function endableContract(): ?EmployeeContract
    {
        $this->loadMissing(['employeeContracts.terminationDocument']);

        return $this->employeeContracts
            ->sortByDesc(fn (EmployeeContract $contract): string => sprintf(
                '%s-%020d',
                $contract->effective_date_from?->toDateString() ?? '0000-00-00',
                (int) $contract->getKey(),
            ))
            ->first(fn (EmployeeContract $contract): bool => $contract->canEnd());
    }

    public function oneTimePayments(): HasMany
    {
        return $this->hasMany(EmployeeOneTimePayment::class);
    }

    public function monthlyPayments(): HasMany
    {
        return $this->hasMany(EmployeeMonthlyPayment::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(EmployeeChild::class)->orderBy('birthdate');
    }
}
