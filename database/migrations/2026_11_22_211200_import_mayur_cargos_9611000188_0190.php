<?php

use App\Enums\CargoStatus;
use App\Enums\ImportTaxPaymentStatus;
use App\Enums\WarehouseImportStatus;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Contact;
use App\Models\CustomsContact;
use App\Models\ImportTax;
use App\Models\ImportTaxPayment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseImport;
use App\Services\ContactMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Import Mayur cargos 9611000190 (Vilnius, received) and 9611000188 (Hamburg, shipped),
 * plus Baltic Air + customs expense invoices from the Nov 2026 goods PDFs.
 * Snapshot: database/data/mayur-cargos-9611000188-0190.json.
 * Idempotent by cargo tracking / invoice number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('cargos') || ! Schema::hasTable('products')) {
            return;
        }

        $path = database_path('data/mayur-cargos-9611000188-0190.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{cargos?: list<array<string, mixed>>, expense_invoices?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $supplier = Supplier::query()->where('name', 'like', '%Mayur%')->orderBy('id')->first();
        if ($supplier === null) {
            return;
        }

        $uploaderId = (int) (User::query()->orderBy('id')->value('id') ?? 1);
        $importTaxId = ImportTax::query()->orderByDesc('rate')->orderBy('id')->value('id');
        $customsContactId = CustomsContact::defaultId() ?? CustomsContact::query()->value('id');

        foreach ($payload['cargos'] ?? [] as $cargoData) {
            $this->importCargo(
                $cargoData,
                (int) $supplier->id,
                $importTaxId !== null ? (int) $importTaxId : null,
                $customsContactId !== null ? (int) $customsContactId : null,
            );
        }

        if (Schema::hasTable('invoices')) {
            foreach ($payload['expense_invoices'] ?? [] as $invoiceData) {
                $this->upsertExpenseInvoice($invoiceData, $uploaderId);
            }
        }
    }

    public function down(): void
    {
        // Keep imported cargos/invoices; not safely reversible.
    }

    /**
     * @param  array<string, mixed>  $cargoData
     */
    private function importCargo(array $cargoData, int $supplierId, ?int $importTaxId, ?int $customsContactId): void
    {
        $tracking = trim((string) ($cargoData['tracking'] ?? ''));
        if ($tracking === '') {
            return;
        }

        $status = CargoStatus::tryFrom((string) ($cargoData['status'] ?? '')) ?? CargoStatus::Shipped;
        $shipped = (string) ($cargoData['invoice_date'] ?? now()->toDateString());
        $actual = $cargoData['actual_date'] ?? ($status === CargoStatus::Received ? $shipped : null);

        $cargo = Cargo::query()
            ->where('supplier_id', $supplierId)
            ->where('tracking', $tracking)
            ->first();

        if ($cargo === null) {
            $cargo = Cargo::query()->create([
                'supplier_id' => $supplierId,
                'tracking' => $tracking,
                'date_shipped' => $shipped,
                'estimated_arrival' => $actual ?? $shipped,
                'actual_date' => $actual,
                'status' => $status,
                'import_tax_id' => $importTaxId,
                'shipping_cost' => round((float) ($cargoData['shipping_cost'] ?? 0), 2),
                'additional_cost' => round((float) ($cargoData['additional_cost'] ?? 0), 2),
                'customs_contact_id' => $customsContactId,
            ]);
        } else {
            $cargo->forceFill([
                'status' => $status,
                'date_shipped' => $cargo->date_shipped ?? $shipped,
                'estimated_arrival' => $cargo->estimated_arrival ?? ($actual ?? $shipped),
                'actual_date' => $cargo->actual_date ?? $actual,
                'shipping_cost' => round((float) ($cargoData['shipping_cost'] ?? $cargo->shipping_cost ?? 0), 2),
                'additional_cost' => round((float) ($cargoData['additional_cost'] ?? $cargo->additional_cost ?? 0), 2),
                'import_tax_id' => $cargo->import_tax_id ?? $importTaxId,
                'customs_contact_id' => $cargo->customs_contact_id ?? $customsContactId,
            ])->save();
        }

        DB::transaction(function () use ($cargo, $cargoData, $status, $actual, $shipped, $importTaxId, $customsContactId): void {
            foreach ($cargoData['lines'] ?? [] as $line) {
                $product = $this->resolveProduct($line);
                if ($product === null) {
                    continue;
                }

                $rolls = max(1, (int) ($line['rolls'] ?? 0));
                $unitPrice = (float) ($line['unit_price_eur'] ?? 0);

                $existingItem = CargoItem::query()
                    ->where('cargo_id', $cargo->id)
                    ->where('product_id', $product->id)
                    ->first();

                if ($existingItem === null) {
                    CargoItem::query()->create([
                        'cargo_id' => $cargo->id,
                        'product_id' => $product->id,
                        'amount' => $rolls,
                        'self_cost' => $unitPrice,
                    ]);
                }

                if ($status === CargoStatus::Received) {
                    $existingImport = WarehouseImport::query()
                        ->where('cargo_id', $cargo->id)
                        ->where('product_id', $product->id)
                        ->first();

                    if ($existingImport === null) {
                        $baseCost = $product->lineAmount($unitPrice, 1);
                        WarehouseImport::query()->create([
                            'product_id' => $product->id,
                            'cargo_id' => $cargo->id,
                            'cost' => $baseCost,
                            'base_cost' => $baseCost,
                            'overhead_cost' => 0,
                            'received_date' => $actual ?? $shipped,
                            'amount' => $rolls,
                            'status' => WarehouseImportStatus::Received,
                        ]);
                    }
                }
            }

            $customs = $cargoData['customs'] ?? null;
            if (is_array($customs) && (float) ($customs['tax_amount'] ?? 0) > 0) {
                $payment = ImportTaxPayment::query()->firstOrNew(['cargo_id' => $cargo->id]);
                $payment->fill([
                    'customs_contact_id' => $customsContactId,
                    'import_tax_id' => $importTaxId,
                    'tax_rate' => ImportTax::query()->find($importTaxId)?->rate ?? 0,
                    'amount' => max(1, (int) array_sum(array_map(
                        fn (array $line): int => (int) ($line['rolls'] ?? 0),
                        $cargoData['lines'] ?? [],
                    ))),
                    'line_value' => round((float) ($cargoData['goods_value_eur'] ?? 0), 2),
                    'tax_amount' => round((float) $customs['tax_amount'], 2),
                    'service_cost' => 0,
                    'status' => ImportTaxPaymentStatus::Payed,
                    'received_date' => $customs['paid_date'] ?? $actual ?? $shipped,
                ]);
                $payment->save();
            } elseif ($status === CargoStatus::Received) {
                $cargo->load(['cargoItems.product.productType', 'importTax']);
                ImportTaxPayment::syncFromCargo($cargo->fresh(['cargoItems.product.productType', 'importTax']));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveProduct(array $line): ?Product
    {
        $collectionName = strtoupper(trim((string) ($line['collection'] ?? '')));
        $colorCode = strtoupper(trim((string) ($line['color_code'] ?? '')));
        $colorName = strtoupper(trim((string) ($line['color_name'] ?? '')));
        $size = trim((string) ($line['size'] ?? ''));

        if ($collectionName === '' || $colorCode === '' || $size === '') {
            return null;
        }

        $colorNameCompact = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $colorName) ?? '');
        $alternative = $collectionName.$colorCode.$colorNameCompact.$size;

        $existing = Product::query()
            ->where(function ($query) use ($alternative, $collectionName, $colorCode, $size): void {
                $query->whereRaw('UPPER(alternative_code) = ?', [$alternative])
                    ->orWhereRaw('UPPER(alternative_code) = ?', [$collectionName.$colorCode.$size]);
            })
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $existing = Product::query()
            ->whereHas('color.collection', fn ($q) => $q->whereRaw('UPPER(name) = ?', [$collectionName]))
            ->whereHas('color', fn ($q) => $q->where('color_code', $colorCode))
            ->where('name', $size)
            ->first();

        if ($existing !== null) {
            if (blank($existing->alternative_code)) {
                $existing->forceFill(['alternative_code' => $alternative])->save();
            }

            return $existing;
        }

        $supplierId = (int) Supplier::query()->where('name', 'like', '%Mayur%')->orderBy('id')->value('id');
        $template = Collection::query()
            ->where('supplier_id', $supplierId)
            ->where('name', 'Agnona')
            ->first();

        $collection = Collection::query()
            ->where('supplier_id', $supplierId)
            ->whereRaw('UPPER(name) = ?', [$collectionName])
            ->first();

        if ($collection === null) {
            $collection = Collection::query()->create([
                'name' => Str::title(strtolower($collectionName)),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'cost' => (float) ($template?->cost ?? 0),
            ]);
        }

        $color = Color::query()
            ->where('collection_id', $collection->id)
            ->where('color_code', $colorCode)
            ->first();

        if ($color === null) {
            $color = Color::query()->create([
                'collection_id' => $collection->id,
                'color_code' => $colorCode,
                'color_name' => Str::title(strtolower(str_replace('_', ' ', $colorName))),
                'recommended_stock' => 0,
                'default_order' => 0,
            ]);
        }

        $productTypeId = ProductType::query()->where('name', 'Artificial leather')->value('id')
            ?? ProductType::query()->orderBy('id')->value('id');

        return Product::query()->create([
            'product_type_id' => $productTypeId,
            'name' => $size,
            'color_id' => $color->id,
            'alternative_code' => $alternative,
            'cost' => (float) ($collection->cost ?? $template?->cost ?? 0),
            'current_amount' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertExpenseInvoice(array $data, int $uploaderId): void
    {
        $number = trim((string) ($data['invoice_number'] ?? ''));
        $contactName = trim((string) ($data['contact_name'] ?? ''));
        if ($number === '' || $contactName === '') {
            return;
        }

        $contact = match (true) {
            str_contains(mb_strtolower($contactName), 'baltic air logistics') => $this->ensureBalticAirContact(),
            str_contains(mb_strtolower($contactName), 'muitin') => $this->ensureCustomsContact(),
            default => ContactMerger::matchExisting($contactName),
        };

        if ($contact === null) {
            return;
        }

        $existing = Invoice::findByNumber($number);
        if ($existing !== null) {
            if ((int) $existing->contact_id !== (int) $contact->id) {
                $existing->forceFill(['contact_id' => $contact->id])->save();
            }

            return;
        }

        $parsedFinance = Invoice::parseFinanceTypeFilterValue($contact->default_finance_type);
        $expenseTypeId = is_array($parsedFinance) && ($parsedFinance['kind'] ?? null) === 'expense'
            ? (int) ($parsedFinance['type_id'] ?? 0)
            : 0;
        if ($expenseTypeId <= 0) {
            $expenseTypeId = (int) (Invoice::goodsExpenseTypeId() ?? 0);
        }

        Invoice::query()->create([
            'invoice_number' => $number,
            'contact_id' => $contact->id,
            'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
            'sum_without_vat' => round((float) ($data['sum_without_vat'] ?? 0), 2),
            'vat' => round((float) ($data['vat'] ?? 0), 2),
            'sum_inc_vat' => round((float) ($data['sum_inc_vat'] ?? 0), 2),
            'currency' => 'EUR',
            'expense_type_id' => $expenseTypeId > 0 ? $expenseTypeId : null,
            'income_type_id' => null,
            'is_own_account' => false,
            'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $uploaderId,
            'pdf_path' => '',
            'file_mime' => 'application/pdf',
            'paid_amount' => 0,
            'paid' => false,
        ]);
    }

    private function ensureBalticAirContact(): Contact
    {
        $existing = ContactMerger::matchExisting('Baltic Air Logistics Terminal UAB')
            ?? ContactMerger::matchExisting('Baltic Air Logistics Terminal, UAB')
            ?? Contact::query()
                ->where('company_name', 'like', '%Baltic Air Logistics%')
                ->where('company_name', 'not like', '%Air Baltic%')
                ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Contact::query()->create([
            'company_name' => 'Baltic Air Logistics Terminal UAB',
            'company_id' => '124430488',
            'company_vat' => 'LT244304811',
            'company_address' => 'Rodūnios kelias 18, 02188 Vilnius, Lietuva',
            'company_country' => 'LT',
            'default_finance_type' => 'expense:'.Invoice::goodsExpenseTypeId(),
        ]);
    }

    private function ensureCustomsContact(): Contact
    {
        $existing = ContactMerger::matchExisting('Muitinės departamentas prie LR FM')
            ?? ContactMerger::matchExisting('Muitines departamentas prie LR FM')
            ?? Contact::query()->where('company_name', 'like', '%Muitin%')->first()
            ?? Contact::query()->where('company_name', 'like', '%departmentas prie Lietuvos%')->first();

        if ($existing !== null) {
            return $existing;
        }

        return Contact::query()->create([
            'company_name' => 'Muitinės departamentas prie LR FM',
            'company_id' => '188659387',
            'company_vat' => 'LT100001978215',
            'company_address' => 'A. Jakšto g. 1, 01105 Vilnius, Lietuva',
            'company_country' => 'LT',
            'default_finance_type' => 'expense:'.Invoice::goodsExpenseTypeId(),
        ]);
    }
};
