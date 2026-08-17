<?php

namespace App\Observers;

use App\Models\ManualImport;
use App\Services\ManualImportApplier;

class ManualImportObserver
{
    /**
     * @var array<int|string, array{product_id: int, amount: int, price: float}>
     */
    private array $beforeUpdate = [];

    public function created(ManualImport $import): void
    {
        ManualImportApplier::applyCreate($import);
    }

    public function updating(ManualImport $import): void
    {
        $this->beforeUpdate[$import->getKey() ?? spl_object_id($import)] = [
            'product_id' => (int) $import->getOriginal('product_id'),
            'amount' => (int) $import->getOriginal('amount'),
            'price' => (float) $import->getOriginal('price'),
        ];
    }

    public function updated(ManualImport $import): void
    {
        $key = $import->getKey() ?? spl_object_id($import);
        $old = $this->beforeUpdate[$key] ?? null;
        unset($this->beforeUpdate[$key]);

        if ($old === null) {
            return;
        }

        if (
            $old['product_id'] === (int) $import->product_id
            && $old['amount'] === (int) $import->amount
            && abs($old['price'] - (float) $import->price) < 0.00001
        ) {
            return;
        }

        ManualImportApplier::applyUpdate(
            $import,
            $old['product_id'],
            $old['amount'],
            $old['price'],
        );
    }

    public function deleting(ManualImport $import): void
    {
        ManualImportApplier::applyDelete($import);
    }
}
