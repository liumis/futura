<?php

namespace App\Models;

use App\Enums\DividendPaymentStatus;
use App\Services\LithuanianDividendCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dividend extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'date',
        'amount',
        'gpm_amount',
        'net_amount',
        'status',
        'comment',
        'is_paid',
        'paid_at',
        'dividend_payment_report_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'gpm_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'status' => DividendPaymentStatus::class,
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function dividendPaymentReport(): BelongsTo
    {
        return $this->belongsTo(DividendPaymentReport::class, 'dividend_payment_report_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Dividend $dividend): void {
            $tax = app(LithuanianDividendCalculator::class)
                ->calculate((float) ($dividend->amount ?? 0));

            $dividend->gpm_amount = $tax['gpm'];
            $dividend->net_amount = $tax['net'];
        });
    }

    /**
     * Compatibility with existing SEPA/VMI exporters that expect `payment_date`.
     */
    public function getPayment_dateAttribute(): mixed
    {
        return $this->date;
    }

    public function isLocked(): bool
    {
        return ($this->status ?? DividendPaymentStatus::Open)->isLocked();
    }

    public function markPayed(): void
    {
        if (($this->status ?? DividendPaymentStatus::Open) === DividendPaymentStatus::Payed) {
            return;
        }

        $this->forceFill([
            'status' => DividendPaymentStatus::Payed,
            'is_paid' => true,
            'paid_at' => now(),
        ])->save();
    }

    public function markWrong(): void
    {
        if (($this->status ?? DividendPaymentStatus::Open) !== DividendPaymentStatus::Payed) {
            return;
        }

        $this->forceFill([
            'status' => DividendPaymentStatus::Wrong,
            'is_paid' => false,
            'paid_at' => null,
        ])->save();
    }

    /**
     * @param  array{gpm: float, net: float}  $tax
     */
    public function applyTaxSnapshot(array $tax): void
    {
        $this->forceFill([
            'gpm_amount' => $tax['gpm'],
            'net_amount' => $tax['net'],
        ])->save();
    }
}
