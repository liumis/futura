<?php

namespace App\Models;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Services\BusinessTripAllowanceCalculator;
use App\Services\BusinessTripAllowanceTaxCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

class BusinessTripRequest extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'business_trip_daily_allowance_id',
        'country_name',
        'daily_rate',
        'date_from',
        'date_to',
        'first_day_hours',
        'last_day_hours',
        'calendar_days',
        'payable_units',
        'amount',
        'tax_year',
        'tax_month',
        'salary_snapshot',
        'salary_threshold_snapshot',
        'is_low_salary_case',
        'month_free_cap_snapshot',
        'non_taxable_amount',
        'taxable_amount',
        'gpm_amount',
        'payable_amount',
        'expenses_amount',
        'purpose',
        'comment',
        'status',
        'confirmed_by',
        'confirmed_at',
        'payment_status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'first_day_hours' => 'decimal:2',
            'last_day_hours' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'payable_units' => 'decimal:2',
            'amount' => 'decimal:2',
            'calendar_days' => 'integer',
            'tax_year' => 'integer',
            'tax_month' => 'integer',
            'salary_snapshot' => 'decimal:2',
            'salary_threshold_snapshot' => 'decimal:2',
            'is_low_salary_case' => 'boolean',
            'month_free_cap_snapshot' => 'decimal:2',
            'non_taxable_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gpm_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'expenses_amount' => 'decimal:2',
            'status' => BusinessTripRequestStatus::class,
            'payment_status' => BusinessTripPaymentStatus::class,
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dailyAllowance(): BelongsTo
    {
        return $this->belongsTo(BusinessTripDailyAllowance::class, 'business_trip_daily_allowance_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'business_trip_request_document')
            ->withTimestamps()
            ->orderByPivot('id');
    }

    public function documentsListHtml(): HtmlString
    {
        $this->loadMissing('documents');

        if ($this->documents->isEmpty()) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">No documents on SharePoint yet.</p>',
            );
        }

        $items = $this->documents->flatMap(function (Document $document): array {
            $fileLinks = $document->attachedFileLinks();

            if ($fileLinks === []) {
                $url = $document->displayFileUrl() ?: $document->sharepoint_web_url;
                if (! filled($url)) {
                    return ['<li class="text-sm">'.e($document->name ?: 'Document').'</li>'];
                }

                return [
                    '<li class="text-sm"><a href="'.e((string) $url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'
                    .e($document->name ?: 'Document')
                    .'</a></li>',
                ];
            }

            return collect($fileLinks)->map(function (array $file) use ($document): string {
                $label = e((string) ($file['name'] ?? $document->name ?: 'Document'));
                $url = $file['url'] ?? null;

                if (filled($url)) {
                    return '<li class="text-sm"><a href="'.e((string) $url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'
                        .$label.'</a></li>';
                }

                return '<li class="text-sm">'.$label.'</li>';
            })->all();
        })->implode('');

        return new HtmlString('<ul class="list-disc space-y-1 pl-5">'.$items.'</ul>');
    }

    public function bankStatementTransactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class);
    }

    public function isEditable(): bool
    {
        return $this->status === BusinessTripRequestStatus::New
            && $this->payment_status === BusinessTripPaymentStatus::Unpaid;
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    public function canConfirm(): bool
    {
        return $this->status === BusinessTripRequestStatus::New
            && $this->payment_status === BusinessTripPaymentStatus::Unpaid
            && (float) $this->amount > 0;
    }

    public function canGenerateSepa(): bool
    {
        return $this->status === BusinessTripRequestStatus::Confirmed
            && $this->payment_status === BusinessTripPaymentStatus::Unpaid
            && (float) $this->payableAmount() > 0;
    }

    /**
     * Net amount paid to the employee (gross minus GPM).
     */
    public function payableAmount(): float
    {
        $payable = $this->payable_amount;

        if ($payable !== null && (float) $payable > 0) {
            return round((float) $payable, 2);
        }

        return round((float) $this->amount, 2);
    }

    /**
     * Other trip costs attached (tickets, hotels, etc.) — entered from receipts.
     */
    public function expensesAmount(): float
    {
        return round(max(0, (float) ($this->expenses_amount ?? 0)), 2);
    }

    /**
     * Allowance payable to employee + other attached expenses.
     */
    public function expectedTotal(): float
    {
        return round($this->payableAmount() + $this->expensesAmount(), 2);
    }

    /**
     * Absolute sum of bank statement lines linked to this trip.
     */
    public function linkedBankPaidTotal(): float
    {
        $this->loadMissing('bankStatementTransactions');

        return round((float) $this->bankStatementTransactions->sum(
            fn (BankStatementTransaction $row): float => abs((float) $row->amount),
        ), 2);
    }

    /**
     * @return array{
     *     status: 'paid'|'partly_paid'|'unpaid',
     *     label: string,
     *     expected_total: float,
     *     allowance_amount: float,
     *     expenses_amount: float,
     *     paid_amount: float,
     *     left_amount: float,
     *     payment_count: int,
     *     detail: ?string
     * }
     */
    public function settlementSummary(): array
    {
        $this->loadMissing('bankStatementTransactions');

        $allowance = $this->payableAmount();
        $expenses = $this->expensesAmount();
        $expected = round($allowance + $expenses, 2);
        $paid = $this->linkedBankPaidTotal();
        $count = $this->bankStatementTransactions->count();
        $left = round(max(0, $expected - $paid), 2);

        if ($expected <= 0.02 && $paid <= 0.02) {
            $status = 'unpaid';
            $label = 'Unpaid';
        } elseif ($paid <= 0.02) {
            $status = 'unpaid';
            $label = 'Unpaid';
        } elseif ($expected > 0.02 && $paid + 0.02 >= $expected) {
            $status = 'paid';
            $label = 'Paid';
        } elseif ($expected <= 0.02 && $paid > 0.02) {
            $status = 'paid';
            $label = 'Paid';
        } else {
            $status = 'partly_paid';
            $label = 'Partly paid';
        }

        $detailParts = array_filter([
            $allowance > 0.02 ? 'allowance '.\App\Support\Money::format($allowance) : null,
            $expenses > 0.02 ? 'expenses '.\App\Support\Money::format($expenses) : null,
            $count > 0 ? 'paid '.\App\Support\Money::format($paid) : null,
            $left > 0.02 ? 'left '.\App\Support\Money::format($left) : null,
            $count > 1 ? $count.' payments' : null,
        ]);

        return [
            'status' => $status,
            'label' => $label,
            'expected_total' => $expected,
            'allowance_amount' => $allowance,
            'expenses_amount' => $expenses,
            'paid_amount' => $paid,
            'left_amount' => $left,
            'payment_count' => $count,
            'detail' => $detailParts !== [] ? implode(' · ', $detailParts) : null,
        ];
    }

    /**
     * Refresh payment_status / paid_at from linked bank lines vs expected total.
     */
    public function syncPaymentStatusFromBankLinks(): void
    {
        $summary = $this->settlementSummary();
        $status = match ($summary['status']) {
            'paid' => BusinessTripPaymentStatus::Paid,
            'partly_paid' => BusinessTripPaymentStatus::Partly,
            default => BusinessTripPaymentStatus::Unpaid,
        };

        $paidAt = null;
        if ($status !== BusinessTripPaymentStatus::Unpaid) {
            $this->loadMissing('bankStatementTransactions');
            $paidAt = $this->bankStatementTransactions
                ->map(fn (BankStatementTransaction $row) => $row->booking_date)
                ->filter()
                ->sortByDesc(fn ($date) => $date->format('Y-m-d'))
                ->first();
            $paidAt = $paidAt?->copy()->startOfDay() ?? now();
        }

        if (
            $this->payment_status === $status
            && (
                ($status === BusinessTripPaymentStatus::Unpaid && $this->paid_at === null)
                || ($status !== BusinessTripPaymentStatus::Unpaid && $this->paid_at !== null)
            )
        ) {
            return;
        }

        $this->forceFill([
            'payment_status' => $status,
            'paid_at' => $status === BusinessTripPaymentStatus::Unpaid ? null : $paidAt,
        ])->save();
    }

    /**
     * Recalculate payable units, gross amount, and GPM split.
     */
    public function recalculateAmounts(): void
    {
        $result = BusinessTripAllowanceCalculator::calculate(
            (float) $this->daily_rate,
            $this->date_from,
            $this->date_to,
            $this->first_day_hours !== null ? (float) $this->first_day_hours : null,
            $this->last_day_hours !== null ? (float) $this->last_day_hours : null,
        );

        $this->calendar_days = $result['calendar_days'];
        $this->payable_units = $result['payable_units'];
        $this->amount = $result['amount'];

        $this->recalculateTax();
    }

    public function recalculateTax(): void
    {
        if ((int) $this->employee_id <= 0) {
            $this->non_taxable_amount = $this->amount;
            $this->taxable_amount = 0;
            $this->gpm_amount = 0;
            $this->payable_amount = $this->amount;
            $this->is_low_salary_case = false;

            return;
        }

        BusinessTripAllowanceTaxCalculator::applyTo($this);
    }

    public function applyAllowance(BusinessTripDailyAllowance $allowance): void
    {
        $this->business_trip_daily_allowance_id = $allowance->getKey();
        $this->country_name = $allowance->country;
        $this->daily_rate = $allowance->daily_rate;
    }
}
