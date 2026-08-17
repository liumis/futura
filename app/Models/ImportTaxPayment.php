<?php

namespace App\Models;

use App\Services\CargoCostCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportTaxPayment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cargo_id',
        'import_tax_id',
        'tax_rate',
        'amount',
        'line_value',
        'tax_amount',
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
            'received_date' => 'date',
            'amount' => 'integer',
            'documents' => 'array',
        ];
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function importTax(): BelongsTo
    {
        return $this->belongsTo(ImportTax::class);
    }

    public function documentCount(): int
    {
        return count($this->documents ?? []);
    }

    public static function syncFromCargo(Cargo $cargo, ?string $receivedDate = null): void
    {
        $cargo->loadMissing(['cargoItems', 'importTax']);

        $receivedDate ??= now()->toDateString();
        $rate = (float) ($cargo->importTax?->rate ?? 0);

        $totalAmount = 0;
        $lineValue = 0.0;

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $totalAmount += $item->amount;
            $lineValue += $item->amount * (float) $item->self_cost;
        }

        self::query()->updateOrCreate(
            ['cargo_id' => $cargo->id],
            [
                'import_tax_id' => $cargo->import_tax_id,
                'tax_rate' => $rate,
                'amount' => $totalAmount,
                'line_value' => round($lineValue, 2),
                'tax_amount' => CargoCostCalculator::importTaxesFor($cargo),
                'received_date' => $receivedDate,
            ],
        );
    }
}
