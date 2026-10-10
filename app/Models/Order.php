<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_reserved' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'status',
        'stock_reserved',
        'amount',
        'shipping_cost',
        'tracking_number',
        'order_date',
        'warehouse_email_sent_at',
        'user_id',
        'package_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'stock_reserved' => 'boolean',
            'amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'order_date' => 'date',
            'warehouse_email_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoiceCollection(): Collection
    {
        if ($this->relationLoaded('invoices')) {
            return $this->invoices;
        }

        return $this->invoices()->get();
    }

    /**
     * Aggregated invoice settlement for this order.
     *
     * @return 'paid'|'partly_paid'|'unpaid'|null null when the order has no invoice yet
     */
    public function paymentSettlementStatus(): ?string
    {
        $invoices = $this->invoiceCollection();

        if ($invoices->isEmpty()) {
            return null;
        }

        if ($invoices->every(fn (Invoice $invoice): bool => $invoice->isFullyPaid())) {
            return 'paid';
        }

        $hasProgress = $invoices->contains(
            fn (Invoice $invoice): bool => $invoice->isFullyPaid() || $invoice->isPartlyPaid()
        );

        if ($hasProgress) {
            return 'partly_paid';
        }

        return 'unpaid';
    }

    public function paymentSettlementLabel(): string
    {
        return match ($this->paymentSettlementStatus()) {
            'paid' => 'Paid',
            'partly_paid' => $this->partlyPaidSettlementLabel(),
            'unpaid' => 'Not paid',
            default => '—',
        };
    }

    public function paymentSettlementColor(): string
    {
        return match ($this->paymentSettlementStatus()) {
            'paid' => 'success',
            'partly_paid' => 'warning',
            'unpaid' => 'danger',
            default => 'gray',
        };
    }

    protected function partlyPaidSettlementLabel(): string
    {
        $invoices = $this->invoiceCollection();
        $paid = round($invoices->sum(fn (Invoice $invoice): float => (float) ($invoice->paid_amount ?? 0)), 2);
        $total = round($invoices->sum(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat), 2);
        $left = max(0, round($total - $paid, 2));

        return 'Partly paid · paid '.Money::format($paid).' · left '.Money::format($left);
    }
}
