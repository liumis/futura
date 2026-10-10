<?php

namespace App\Models;

use App\Enums\EmployeeContractStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class EmployeeContract extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'sign_date',
        'effective_date_from',
        'valid_to',
        'base_salary',
        'default_bonus',
        'status',
        'state_percentage',
        'document_id',
        'termination_date',
        'termination_reason',
        'termination_document_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state_percentage' => 100,
        'status' => EmployeeContractStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sign_date' => 'date',
            'effective_date_from' => 'date',
            'valid_to' => 'date',
            'termination_date' => 'date',
            'base_salary' => 'decimal:2',
            'default_bonus' => 'decimal:2',
            'status' => EmployeeContractStatus::class,
            'state_percentage' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signings(): HasMany
    {
        return $this->hasMany(EmployeeContractSigning::class);
    }

    public function latestSigning(): HasOne
    {
        return $this->hasOne(EmployeeContractSigning::class)->latestOfMany();
    }

    public function terminationDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'termination_document_id');
    }

    public function canStartSigning(): bool
    {
        return $this->status !== EmployeeContractStatus::Inactive
            && blank($this->termination_document_id);
    }

    public function isTerminated(): bool
    {
        return $this->status === EmployeeContractStatus::Inactive
            && filled($this->termination_date);
    }

    public function hasPendingTermination(): bool
    {
        if (blank($this->termination_document_id) || $this->status === EmployeeContractStatus::Inactive) {
            return false;
        }

        $this->loadMissing('terminationDocument');

        return $this->terminationDocument?->awaitsDokobitSignature() === true
            || $this->terminationDocument?->isApproved() !== true;
    }

    public function canEnd(): bool
    {
        if ($this->status === EmployeeContractStatus::Inactive) {
            return false;
        }

        return $this->status === EmployeeContractStatus::Signed
            || $this->hasPendingTermination();
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     * @return Builder<EmployeeContract>
     */
    public function scopeValidDuringMonth(Builder $query, Carbon|string $month): Builder
    {
        $start = Carbon::parse($month)->startOfMonth()->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        return $query
            ->whereDate('effective_date_from', '<=', $end)
            ->where(function (Builder $builder) use ($start): void {
                $builder
                    ->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $start);
            });
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     * @return Builder<EmployeeContract>
     */
    public function scopeOverlappingPeriod(
        Builder $query,
        int $employeeId,
        Carbon|string $effectiveFrom,
        Carbon|string|null $validTo = null,
        ?int $ignoreId = null,
    ): Builder {
        $start = $effectiveFrom instanceof Carbon ? $effectiveFrom->copy()->startOfDay() : Carbon::parse($effectiveFrom)->startOfDay();
        $end = $validTo instanceof Carbon
            ? $validTo->copy()->endOfDay()
            : (filled($validTo) ? Carbon::parse((string) $validTo)->endOfDay() : null);

        return $query
            ->where('employee_id', $employeeId)
            ->when($ignoreId !== null, fn (Builder $q): Builder => $q->whereKeyNot($ignoreId))
            ->whereDate('effective_date_from', '<=', $end?->toDateString() ?? '9999-12-31')
            ->where(function (Builder $builder) use ($start): void {
                $builder
                    ->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $start->toDateString());
            });
    }

    /**
     * Other contracts for the same employee whose period overlaps this one.
     *
     * @return Collection<int, EmployeeContract>
     */
    public static function findOverlappingForEmployee(
        int $employeeId,
        Carbon|string|null $effectiveFrom,
        Carbon|string|null $validTo = null,
        ?int $ignoreId = null,
    ): Collection {
        if ($employeeId <= 0 || blank($effectiveFrom)) {
            return new Collection;
        }

        return static::query()
            ->overlappingPeriod($employeeId, $effectiveFrom, $validTo, $ignoreId)
            ->orderBy('effective_date_from')
            ->orderBy('id')
            ->get();
    }

    public static function overlapWarningMessage(
        int $employeeId,
        Carbon|string|null $effectiveFrom,
        Carbon|string|null $validTo = null,
        ?int $ignoreId = null,
    ): ?string {
        $overlaps = static::findOverlappingForEmployee($employeeId, $effectiveFrom, $validTo, $ignoreId);

        if ($overlaps->isEmpty()) {
            return null;
        }

        $details = $overlaps
            ->map(function (self $contract): string {
                $from = $contract->effective_date_from?->toDateString() ?? '—';
                $to = $contract->valid_to?->toDateString() ?? 'open-ended';

                return '#'.$contract->getKey().' ('.$from.' – '.$to.')';
            })
            ->implode(', ');

        return 'This contract starts while another contract is still active for the same person: '
            .$details
            .'. You can still save. After this contract is signed, the overlapping contract(s) will get an end date.';
    }

    /**
     * Close overlapping contracts for the same employee by setting valid_to
     * to the day before this contract starts.
     */
    public function closeOverlappingContracts(): int
    {
        if ($this->status !== EmployeeContractStatus::Signed) {
            return 0;
        }

        $from = $this->effective_date_from;
        if (! $from instanceof Carbon) {
            return 0;
        }

        $proposedEnd = $from->copy()->subDay();
        $closed = 0;

        foreach (static::findOverlappingForEmployee(
            employeeId: (int) $this->employee_id,
            effectiveFrom: $from,
            validTo: $this->valid_to,
            ignoreId: $this->exists ? (int) $this->getKey() : null,
        ) as $other) {
            $endDate = $proposedEnd->copy();
            $otherStart = $other->effective_date_from;

            if ($otherStart instanceof Carbon && $endDate->lt($otherStart)) {
                $endDate = $otherStart->copy();
            }

            if ($other->valid_to instanceof Carbon && $other->valid_to->lte($endDate)) {
                continue;
            }

            $other->update([
                'valid_to' => $endDate,
                'status' => EmployeeContractStatus::Inactive,
            ]);
            $closed++;
        }

        return $closed;
    }
}
