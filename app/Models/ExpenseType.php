<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'invoice_code_id',
    ];

    public function invoiceCode(): BelongsTo
    {
        return $this->belongsTo(InvoiceCode::class);
    }
}
