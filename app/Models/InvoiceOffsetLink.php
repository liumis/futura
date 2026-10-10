<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceOffsetLink extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_invoice_id',
        'sales_invoice_id',
        'amount',
        'operation_ref',
        'operation_date',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'operation_date' => 'date',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'purchase_invoice_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'sales_invoice_id');
    }
}
