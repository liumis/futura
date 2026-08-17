<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceFinanceLine extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'name',
        'invoice_code_id',
        'credit',
        'debit',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit' => 'decimal:2',
            'debit' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceCode(): BelongsTo
    {
        return $this->belongsTo(InvoiceCode::class);
    }
}
