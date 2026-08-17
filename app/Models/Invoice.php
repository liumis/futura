<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'order_id',
        'invoice_series_id',
        'series_number',
        'invoice_number',
        'invoice_date',
        'sum_without_vat',
        'vat',
        'sum_inc_vat',
        'income_type_id',
        'expense_type_id',
        'vat_rate_id',
        'upload_date',
        'uploaded_by',
        'pdf_path',
        'file_content',
        'file_name',
        'file_mime',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'sum_without_vat' => 'decimal:2',
            'vat' => 'decimal:2',
            'sum_inc_vat' => 'decimal:2',
            'upload_date' => 'date',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class);
    }

    public function uploadedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function incomeType(): BelongsTo
    {
        return $this->belongsTo(IncomeType::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function financeLines(): HasMany
    {
        return $this->hasMany(InvoiceFinanceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function hasFinanceDetailsWarning(): bool
    {
        $this->loadMissing('financeLines');

        $creditTotal = round((float) $this->financeLines->sum('credit'), 2);
        $debitTotal = round((float) $this->financeLines->sum('debit'), 2);
        $totalToPay = round((float) $this->sum_inc_vat, 2);

        $balanced = abs($creditTotal - $debitTotal) < 0.005;
        $matchesTotalToPay = abs($creditTotal - $totalToPay) < 0.005
            && abs($debitTotal - $totalToPay) < 0.005;

        return ! $balanced || ! $matchesTotalToPay;
    }

    public function financeDetailsWarningMessage(): string
    {
        $this->loadMissing('financeLines');

        $creditTotal = round((float) $this->financeLines->sum('credit'), 2);
        $debitTotal = round((float) $this->financeLines->sum('debit'), 2);
        $totalToPay = round((float) $this->sum_inc_vat, 2);

        $balanced = abs($creditTotal - $debitTotal) < 0.005;
        $matchesTotalToPay = abs($creditTotal - $totalToPay) < 0.005
            && abs($debitTotal - $totalToPay) < 0.005;

        $messages = [];

        if (! $balanced) {
            $messages[] = 'Debit and credit totals are not equal.';
        }

        if ($balanced && ! $matchesTotalToPay) {
            $messages[] = 'Debit/credit total does not equal Total to pay.';
        }

        if ($this->financeLines->isEmpty()) {
            $messages[] = 'No finance lines entered.';
        }

        return implode(' ', $messages) ?: 'Finance details need attention.';
    }
}
