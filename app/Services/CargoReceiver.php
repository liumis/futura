<?php

namespace App\Services;

use App\Enums\ActivityLogEvent;
use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Cargo;
use App\Models\ImportTaxPayment;
use App\Models\WarehouseImport;
use Illuminate\Support\Facades\DB;

class CargoReceiver
{
    /**
     * Mark a warehouse order as received and import stock.
     * Import tax payment rows are created when the customs invoice is uploaded;
     * receive still refreshes amounts/dates on that row.
     *
     * @param  array<string|int, int>  $amounts
     * @param  array<string|int, float>  $costs
     * @param  array<string, mixed>|null  $attributes
     */
    public static function receiveAndImport(
        Cargo $cargo,
        ?array $amounts = null,
        ?array $costs = null,
        ?array $attributes = null,
    ): Cargo {
        if ($cargo->status === CargoStatus::Received) {
            throw new \RuntimeException('Warehouse order already received.');
        }

        $cargo->loadMissing('cargoItems');

        if ($amounts === null || $costs === null) {
            $amounts = [];
            $costs = [];

            foreach ($cargo->cargoItems as $item) {
                $amounts[(string) $item->product_id] = $item->amount;
                $costs[(string) $item->product_id] = (float) $item->self_cost;
            }
        }

        $updateData = $attributes ?? [];
        $updateData['status'] = CargoStatus::Received;

        if (blank($updateData['actual_date'] ?? null)) {
            $updateData['actual_date'] = now()->toDateString();
        }

        DB::transaction(function () use ($cargo, $amounts, $costs, $updateData): void {
            $cargo->update($updateData);
            CargoResource::syncCargoItemsFromAmounts($cargo, $amounts, $costs);

            $receivedCargo = $cargo->fresh(['cargoItems', 'importTax']);
            $receivedDate = $receivedCargo->actual_date?->toDateString() ?? now()->toDateString();

            // Pending warehouse-import rows; stock is added when staff click Import on /warehouse-imports.
            WarehouseImport::syncFromCargo($receivedCargo, $receivedDate);
            ImportTaxPayment::syncFromCargo($receivedCargo, $receivedDate);
        });

        $receivedCargo = $cargo->fresh(['cargoItems', 'importTax']);

        ActivityLogger::log(
            ActivityLogEvent::CargoUpdated,
            'Warehouse order #'.$receivedCargo->id.' received — pending stock import',
            $receivedCargo,
        );

        return $receivedCargo;
    }
}
