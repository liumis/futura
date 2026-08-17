<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ManualImport;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ManualImportApplier
{
    /**
     * Apply stock/price for a newly created manual import.
     */
    public static function applyCreate(ManualImport $import): void
    {
        DB::transaction(function () use ($import): void {
            self::applyStockAndPriceOnCreate($import);
            self::syncInvoice($import);
        });
    }

    /**
     * Adjust stock/price when a manual import is edited.
     */
    public static function applyUpdate(ManualImport $import, int $oldProductId, int $oldAmount, float $oldPrice): void
    {
        DB::transaction(function () use ($import, $oldProductId, $oldAmount, $oldPrice): void {
            $newProductId = (int) $import->product_id;
            $newAmount = max(0, (int) $import->amount);
            $newPrice = (float) $import->price;

            if ($oldProductId !== $newProductId) {
                self::adjustStock($oldProductId, -$oldAmount);
                self::adjustStock($newProductId, $newAmount);
                self::setPrice($newProductId, $newPrice);
            } else {
                $delta = $newAmount - $oldAmount;

                if ($delta !== 0) {
                    self::adjustStock($newProductId, $delta);
                }

                if (abs($newPrice - $oldPrice) > 0.00001) {
                    self::setPrice($newProductId, $newPrice);
                }
            }

            self::syncInvoice($import);
        });
    }

    /**
     * Reverse stock when a manual import is deleted.
     */
    public static function applyDelete(ManualImport $import): void
    {
        DB::transaction(function () use ($import): void {
            self::adjustStock((int) $import->product_id, -max(0, (int) $import->amount));

            $invoiceId = $import->invoice_id;

            if (filled($invoiceId)) {
                $import->forceFill(['invoice_id' => null])->saveQuietly();
                Invoice::query()->whereKey($invoiceId)->delete();
            }
        });
    }

    private static function applyStockAndPriceOnCreate(ManualImport $import): void
    {
        $product = Product::query()->lockForUpdate()->find($import->product_id);

        if ($product === null) {
            throw new RuntimeException('Product not found for manual import.');
        }

        $amount = max(0, (int) $import->amount);

        if ($amount > 0) {
            $product->increment('current_amount', $amount);
        }

        $product->update([
            'default_cost' => number_format((float) $import->price, 2, '.', ''),
        ]);
    }

    public static function syncInvoice(ManualImport $import): void
    {
        $import->refresh();

        if (blank($import->invoice_path)) {
            if (filled($import->invoice_id)) {
                Invoice::query()->whereKey($import->invoice_id)->delete();
                $import->forceFill(['invoice_id' => null])->saveQuietly();
            }

            return;
        }

        if (blank($import->contact_id)) {
            throw new RuntimeException('Company is required when an invoice file is attached.');
        }

        if (! Storage::disk('public')->exists($import->invoice_path)) {
            throw new RuntimeException('Attached invoice file was not found.');
        }

        $binary = Storage::disk('public')->get($import->invoice_path);
        $mime = Storage::disk('public')->mimeType($import->invoice_path) ?: 'application/octet-stream';
        $lineTotal = self::lineTotal($import);
        $invoiceDate = $import->imported_at?->toDateString() ?? now()->toDateString();

        $payload = [
            'contact_id' => $import->contact_id,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $lineTotal,
            'vat' => 0,
            'sum_inc_vat' => $lineTotal,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $import->user_id ?? auth()->id(),
            'pdf_path' => $import->invoice_path,
            'file_content' => base64_encode($binary),
            'file_name' => basename($import->invoice_path),
            'file_mime' => $mime,
        ];

        if (filled($import->invoice_id)) {
            Invoice::query()->whereKey($import->invoice_id)->update($payload);

            return;
        }

        $invoice = Invoice::query()->create($payload);
        $import->forceFill(['invoice_id' => $invoice->id])->saveQuietly();
    }

    public static function lineTotal(ManualImport $import): string
    {
        $import->loadMissing('product.productType');
        $product = $import->product;
        $unit = (float) $import->price;
        $amount = max(0, (int) $import->amount);

        if ($product !== null && ! $product->isCatalog() && is_numeric($product->name)) {
            $total = round($unit * (float) $product->name * $amount, 2);
        } else {
            $total = round($unit * $amount, 2);
        }

        return number_format($total, 2, '.', '');
    }

    private static function adjustStock(int $productId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $product = Product::query()->lockForUpdate()->find($productId);

        if ($product === null) {
            throw new RuntimeException('Product not found for manual import stock adjustment.');
        }

        $next = (int) $product->current_amount + $delta;

        if ($next < 0) {
            throw new RuntimeException(
                "Cannot reduce stock for product #{$productId} below zero (would become {$next})."
            );
        }

        $product->update(['current_amount' => $next]);
    }

    private static function setPrice(int $productId, float $price): void
    {
        Product::query()->whereKey($productId)->update([
            'default_cost' => number_format($price, 2, '.', ''),
        ]);
    }
}
