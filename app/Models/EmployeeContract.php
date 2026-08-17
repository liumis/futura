<?php

namespace App\Models;

use App\Enums\EmployeeContractStatus;
use Illuminate\Database\Eloquent\Builder;
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

    public function canStartSigning(): bool
    {
        return $this->status !== EmployeeContractStatus::Inactive;
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
}
