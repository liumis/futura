<?php

namespace App\Services;

use App\Models\StockManualUpdate;
use App\Models\WriteOffDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WriteOffDocumentCreator
{
    /**
     * @param  Collection<int, StockManualUpdate>  $updates
     */
    public static function create(Collection $updates, Carbon $documentDate): WriteOffDocument
    {
        if ($updates->isEmpty()) {
            throw new \InvalidArgumentException('Select at least one write-off row.');
        }

        $alreadyDocumented = $updates->first(
            fn (StockManualUpdate $update): bool => filled($update->write_off_document_id),
        );

        if ($alreadyDocumented instanceof StockManualUpdate) {
            throw new \InvalidArgumentException('One or more selected rows are already included in a document.');
        }

        $increase = $updates->first(
            fn (StockManualUpdate $update): bool => $update->delta() >= 0,
        );

        if ($increase instanceof StockManualUpdate) {
            throw new \InvalidArgumentException('Write-off documents can only include stock decreases.');
        }

        $document = DB::transaction(function () use ($updates, $documentDate): WriteOffDocument {
            $document = WriteOffDocument::query()->create([
                'document_number' => self::temporaryDocumentNumber(),
                'document_date' => $documentDate->toDateString(),
                'user_id' => auth()->id(),
            ]);

            $document->update([
                'document_number' => sprintf(
                    'WO-%s-%04d',
                    $documentDate->format('Y'),
                    $document->id,
                ),
            ]);

            StockManualUpdate::query()
                ->whereIn('id', $updates->modelKeys())
                ->whereNull('write_off_document_id')
                ->update(['write_off_document_id' => $document->id]);

            return $document->fresh(['user', 'stockManualUpdates.product.color.collection', 'stockManualUpdates.user']);
        });

        // PDF + SharePoint outside the DB transaction (Graph I/O).
        WriteOffDocumentGenerator::storeFor($document);

        return $document->fresh([
            'user',
            'document',
            'stockManualUpdates.product.color.collection',
            'stockManualUpdates.user',
        ]);
    }

    private static function temporaryDocumentNumber(): string
    {
        return 'WO-TMP-'.uniqid();
    }
}
