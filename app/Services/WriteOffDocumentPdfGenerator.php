<?php



namespace App\Services;



use App\Enums\InvoiceLanguage;

use App\Models\CompanySetting;

use App\Models\StockManualUpdate;

use App\Models\WriteOffDocument;

use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Support\Collection;



class WriteOffDocumentPdfGenerator

{

    public static function generate(WriteOffDocument $document, ?InvoiceLanguage $language = null): string

    {

        $language ??= InvoiceLanguage::English;



        $document->loadMissing([

            'user',

            'stockManualUpdates.product.color.collection',

        ]);



        $lines = self::buildLines($document->stockManualUpdates);



        return Pdf::loadView('pdf.write-off-document', [

            'locale' => $language->value,

            't' => WriteOffDocumentPdfTranslator::strings($language),

            'document' => $document,

            'company' => CompanySetting::instance(),

            'lines' => $lines,

            'totalQuantity' => $lines->sum('quantity'),

        ])

            ->setPaper('a4', 'portrait')

            ->output();

    }



    /**

     * @param  Collection<int, StockManualUpdate>  $entries

     * @return Collection<int, array{product_code: ?string, collection: ?string, color: ?string, quantity: float}>

     */

    public static function buildLines(Collection $entries): Collection

    {

        return $entries

            ->sortBy('created_at')

            ->values()

            ->map(function (StockManualUpdate $entry): array {

                $units = abs(min(0, $entry->delta()));

                $size = (float) ($entry->product?->name ?? 0);



                return [

                    'product_code' => $entry->product?->product_code,

                    'collection' => $entry->product?->color?->collection?->name,

                    'color' => $entry->product?->color?->color_name,

                    'quantity' => $size * $units,

                ];

            });

    }



    public static function formatQuantity(float $quantity): string

    {

        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

    }

}

