<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ManualImport extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'contact_id',
        'invoice_id',
        'amount',
        'price',
        'invoice_path',
        'note',
        'imported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'price' => 'decimal:2',
            'imported_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ManualImport $import): void {
            // File is owned by the invoices list when linked; otherwise clean up orphan upload.
            if (filled($import->invoice_id) || blank($import->invoice_path)) {
                return;
            }

            if (Storage::disk('public')->exists($import->invoice_path)) {
                Storage::disk('public')->delete($import->invoice_path);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceUrl(): ?string
    {
        if (blank($this->invoice_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->invoice_path);
    }
}
