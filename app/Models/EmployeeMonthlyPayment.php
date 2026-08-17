<?php

namespace App\Models;

use App\Enums\EmployeeMonthlyPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmployeeMonthlyPayment extends Model
{
    protected $fillable = [
        'employee_id',
        'payment_date',
        'base_salary',
        'bonus_payment',
        'gross_amount',
        'npd_amount',
        'sodra_employee_amount',
        'sodra_employer_amount',
        'gpm_amount',
        'net_amount',
        'comment',
        'status',
        'employee_payment_report_id',
        'is_paid',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'base_salary' => 'decimal:2',
            'bonus_payment' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'npd_amount' => 'decimal:2',
            'sodra_employee_amount' => 'decimal:2',
            'sodra_employer_amount' => 'decimal:2',
            'gpm_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => EmployeeMonthlyPaymentStatus::class,
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function paymentReport(): BelongsTo
    {
        return $this->belongsTo(EmployeePaymentReport::class, 'employee_payment_report_id');
    }

    public function scopeForDate(Builder $query, Carbon|string $date): Builder
    {
        $day = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return $query->whereDate('payment_date', $day);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', EmployeeMonthlyPaymentStatus::Open->value);
    }

    public function isLocked(): bool
    {
        return ($this->status ?? EmployeeMonthlyPaymentStatus::Open)->isLocked();
    }

    public function markPayed(): void
    {
        if ($this->status === EmployeeMonthlyPaymentStatus::Payed) {
            return;
        }

        $this->forceFill([
            'status' => EmployeeMonthlyPaymentStatus::Payed,
            'is_paid' => true,
            'paid_at' => now(),
        ])->save();
    }

    public function markWrong(): void
    {
        if ($this->status !== EmployeeMonthlyPaymentStatus::Payed) {
            return;
        }

        $this->forceFill([
            'status' => EmployeeMonthlyPaymentStatus::Wrong,
            'is_paid' => false,
            'paid_at' => null,
        ])->save();
    }

    /** @deprecated Use markPayed() */
    public function markPaid(): void
    {
        $this->markPayed();
    }

    /**
     * @param  array{gross: float, npd: float, sodra_employee: float, sodra_employer?: float, gpm: float, net: float}  $tax
     */
    public function applyTaxSnapshot(array $tax): void
    {
        $this->forceFill([
            'gross_amount' => $tax['gross'],
            'npd_amount' => $tax['npd'],
            'sodra_employee_amount' => $tax['sodra_employee'],
            'sodra_employer_amount' => $tax['sodra_employer'] ?? null,
            'gpm_amount' => $tax['gpm'],
            'net_amount' => $tax['net'],
        ]);
    }
}
