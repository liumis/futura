<?php

namespace App\Models;

use App\Enums\CargoStatus;
use Database\Factories\CargoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\HtmlString;

class Cargo extends Model
{
    /** @use HasFactory<CargoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'tracking',
        'date_shipped',
        'estimated_arrival',
        'actual_date',
        'status',
        'email_sent_at',
        'import_tax_id',
        'shipping_cost',
        'additional_cost',
        'customs_contact_id',
        'customs_invoice_file_name',
        'customs_invoice_sharepoint_web_url',
        'customs_invoice_sharepoint_item_id',
        'customs_invoice_sharepoint_path',
        'customs_documents',
        'customs_email_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_shipped' => 'date',
            'estimated_arrival' => 'date',
            'actual_date' => 'date',
            'status' => CargoStatus::class,
            'email_sent_at' => 'datetime',
            'shipping_cost' => 'decimal:2',
            'additional_cost' => 'decimal:2',
            'customs_documents' => 'array',
            'customs_email_sent_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function importTax(): BelongsTo
    {
        return $this->belongsTo(ImportTax::class);
    }

    public function customsContact(): BelongsTo
    {
        return $this->belongsTo(CustomsContact::class);
    }

    public function cargoItems(): HasMany
    {
        return $this->hasMany(CargoItem::class);
    }

    public function importTaxPayment(): HasOne
    {
        return $this->hasOne(ImportTaxPayment::class);
    }

    public function hasCustomsInvoice(): bool
    {
        return filled($this->customs_invoice_sharepoint_path)
            || filled($this->customs_invoice_sharepoint_web_url)
            || filled($this->customs_invoice_sharepoint_item_id);
    }

    public function hasCustomsDocuments(): bool
    {
        return $this->customsDocumentsList() !== [];
    }

    /**
     * @return list<array{name: string, path: ?string, item_id: ?string, web_url: ?string, original_name: string, uploaded_at?: string}>
     */
    public function customsDocumentsList(): array
    {
        $docs = [];

        foreach ($this->customs_documents ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

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

    public function customsDocumentsListHtml(): HtmlString
    {
        $docs = $this->customsDocumentsList();

        if ($docs === []) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">No customs documents on SharePoint yet.</p>');
        }

        $items = [];

        foreach ($docs as $doc) {
            $label = e((string) ($doc['original_name'] ?? $doc['name'] ?? 'Document'));
            $url = $doc['web_url'] ?? null;

            if (filled($url)) {
                $items[] = '<li class="text-sm"><a href="'.e((string) $url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'.$label.'</a></li>';
            } else {
                $items[] = '<li class="text-sm">'.$label.'</li>';
            }
        }

        return new HtmlString('<ul class="list-disc space-y-1 pl-5">'.implode('', $items).'</ul>');
    }
}
