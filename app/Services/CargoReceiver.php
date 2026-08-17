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
     * Mark a warehouse order as received and import stock, received orders, and import taxes.
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

        DB::transaction(function () use ($cargo, $amounts, $costs, $updateData): void {
            $cargo->update($updateData);
            CargoResource::syncCargoItemsFromAmounts($cargo, $amounts, $costs);
            CargoResource::importCargoItemsToProductStock($cargo);

            $receivedCargo = $cargo->fresh(['cargoItems', 'importTax']);
            $receivedDate = now()->toDateString();

            WarehouseImport::syncFromCargo($receivedCargo, $receivedDate);
            ImportTaxPayment::syncFromCargo($receivedCargo, $receivedDate);
        });

        $receivedCargo = $cargo->fresh(['cargoItems', 'importTax']);

        ActivityLogger::log(
            ActivityLogEvent::CargoUpdated,
            'Warehouse order #'.$receivedCargo->id.' received and stock imported',
            $receivedCargo,
        );

        return $receivedCargo;
    }
}
