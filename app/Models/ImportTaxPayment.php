<?php

namespace App\Models;

use App\Enums\ImportTaxPaymentStatus;
use App\Services\CargoCostCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

class ImportTaxPayment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cargo_id',
        'customs_contact_id',
        'import_tax_id',
        'tax_rate',
        'amount',
        'line_value',
        'tax_amount',
        'service_cost',
        'status',
        'received_date',
        'documents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'line_value' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'service_cost' => 'decimal:2',
            'status' => ImportTaxPaymentStatus::class,
            'received_date' => 'date',
            'amount' => 'integer',
            'documents' => 'array',
        ];
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function customsContact(): BelongsTo
    {
        return $this->belongsTo(CustomsContact::class);
    }

    public function importTax(): BelongsTo
    {
        return $this->belongsTo(ImportTax::class);
    }

    /**
     * Customs invoice from the linked warehouse order (SharePoint).
     *
     * @return array{kind: string, name: string, path: ?string, item_id: ?string, web_url: ?string, original_name: string}|null
     */
    public function customsInvoiceDocument(): ?array
    {
        $this->loadMissing('cargo');
        $cargo = $this->cargo;

        if ($cargo === null || ! $cargo->hasCustomsInvoice()) {
            return null;
        }

        $name = (string) ($cargo->customs_invoice_file_name ?: 'Customs invoice');

        return [
            'kind' => 'customs_invoice',
            'name' => $name,
            'original_name' => $name,
            'path' => $cargo->customs_invoice_sharepoint_path,
            'item_id' => $cargo->customs_invoice_sharepoint_item_id,
            'web_url' => $cargo->customs_invoice_sharepoint_web_url,
        ];
    }

    /**
     * Additional SharePoint documents stored on this payment.
     *
     * @return list<array{name: string, path: ?string, item_id: ?string, web_url: ?string, original_name: string, uploaded_at?: string}>
     */
    public function additionalSharepointDocuments(): array
    {
        $docs = [];

        foreach ($this->documents ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            // Ignore legacy local-only paths (plain strings / paths without SharePoint URL).
            if (! filled($row['web_url'] ?? null) && ! filled($row['path'] ?? null) && ! filled($row['item_id'] ?? null)) {
                continue;
            }

            $docs[] = [
                'name' => (string) ($row['name'] ?? $row['original_name'] ?? 'document'),
                'original_name' => (string) ($row['original_name'] ?? $row['name'] ?? 'document'),
                'path' => filled($row['path'] ?? null) ? (string) $row['path'] : null,
                'item_id' => filled($row['item_id'] ?? null) ? (string) $row['item_id'] : null,
                'web_url' => filled($row['web_url'] ?? null) ? (string) $row['web_url'] : null,
                'uploaded_at' => filled($row['uploaded_at'] ?? null) ? (string) $row['uploaded_at'] : null,
            ];
        }

        return $docs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allSharepointDocuments(): array
    {
        $docs = [];
        $invoice = $this->customsInvoiceDocument();

        if ($invoice !== null) {
            $docs[] = $invoice;
        }

        foreach ($this->additionalSharepointDocuments() as $doc) {
            $docs[] = array_merge(['kind' => 'additional'], $doc);
        }

        return $docs;
    }

    public function documentCount(): int
    {
        return count($this->allSharepointDocuments());
    }

    public function documentsListHtml(): HtmlString
    {
        $docs = $this->allSharepointDocuments();

        if ($docs === []) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">No documents on SharePoint yet.</p>');
        }

        $items = [];

        foreach ($docs as $doc) {
            $label = e((string) ($doc['original_name'] ?? $doc['name'] ?? 'Document'));
            $kind = ($doc['kind'] ?? '') === 'customs_invoice' ? 'Customs invoice' : 'Additional';
            $url = $doc['web_url'] ?? null;

            if (filled($url)) {
                $items[] = '<li class="text-sm"><span class="text-gray-500 dark:text-gray-400">'.e($kind).':</span> '
                    .'<a href="'.e((string) $url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'.$label.'</a></li>';
            } else {
                $items[] = '<li class="text-sm"><span class="text-gray-500 dark:text-gray-400">'.e($kind).':</span> '.$label.'</li>';
            }
        }

        return new HtmlString('<ul class="list-disc space-y-1 pl-5">'.implode('', $items).'</ul>');
    }

    public function totalCost(): float
    {
        return round((float) $this->tax_amount + (float) $this->service_cost, 2);
    }

    public static function syncFromCargo(Cargo $cargo, ?string $receivedDate = null): self
    {
        $cargo->loadMissing(['cargoItems.product.productType', 'importTax']);

        $receivedDate ??= $cargo->actual_date?->toDateString() ?? now()->toDateString();
        $rate = (float) ($cargo->importTax?->rate ?? 0);

        $totalAmount = 0;
        $lineValue = 0.0;

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $totalAmount += $item->amount;
            $lineValue += $item->goodsValue();
        }

        $payment = self::query()->firstOrNew(['cargo_id' => $cargo->id]);
        $wasNew = ! $payment->exists;

        $payment->fill([
            'import_tax_id' => $cargo->import_tax_id,
            'tax_rate' => $rate,
            'amount' => $totalAmount,
            'line_value' => round($lineValue, 2),
            'tax_amount' => CargoCostCalculator::importTaxesFor($cargo),
            'received_date' => $receivedDate,
        ]);

        if ($wasNew && ! $payment->customs_contact_id) {
            $payment->customs_contact_id = CustomsContact::defaultId();
        }

        if ($wasNew) {
            $payment->status ??= ImportTaxPaymentStatus::Unpaid;
            $payment->service_cost ??= 0;
        }

        $payment->save();

        return $payment->fresh(['cargo', 'customsContact', 'importTax']) ?? $payment;
    }
}
