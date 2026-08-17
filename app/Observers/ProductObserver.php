<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Product;
use App\Services\ActivityLogger;

class ProductObserver
{
    public function created(Product $product): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ProductCreated,
            'Product #'.$product->id.' created ('.$this->label($product).')',
            $product,
        );
    }

    public function updated(Product $product): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ProductUpdated,
            'Product #'.$product->id.' updated ('.$this->label($product).')',
            $product,
            ['changes' => $product->getChanges()],
        );
    }

    public function deleted(Product $product): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ProductDeleted,
            'Product #'.$product->id.' deleted ('.$this->label($product).')',
            null,
            [
                'deleted_product_id' => $product->getKey(),
                'color_id' => $product->color_id,
                'product_type_id' => $product->product_type_id,
            ],
        );
    }

    private function label(Product $product): string
    {
        $product->loadMissing(['productType', 'color.collection']);

        if ($product->isCatalog()) {
            return ($product->productType?->name ?? 'Catalog').' · '.($product->name ?? '—');
        }

        $collection = $product->color?->collection?->name ?? '—';
        $colorName = $product->color?->color_name ?? '—';
        $colorCode = $product->color?->color_code ?? '—';

        return "{$collection} · {$colorName} / {$colorCode}";
    }
}
