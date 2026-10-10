<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportHistoricalSalesOrders extends Command
{
    protected $signature = 'app:import-historical-sales-orders
                            {--file= : Path to historical sales JSON (defaults to database/data/historical-sales-orders.json)}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import Futura sales history as completed orders without affecting stock';

    public function handle(HistoricalSalesOrderImporter $importer): int
    {
        $path = $this->option('file') ?: database_path('data/historical-sales-orders.json');

        try {
            $rows = $importer->loadRows($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Loaded '.count($rows).' sales lines from '.$path);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        $beforeStock = (float) Product::query()->sum('current_amount');

        try {
            $result = $importer->import($rows);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $afterStock = (float) Product::query()->sum('current_amount');

        $this->info(sprintf(
            'Customers: created %d, matched %d',
            $result['customers_created'],
            $result['customers_matched'],
        ));
        $this->info(sprintf(
            'Orders: created %d, skipped (already imported) %d',
            $result['orders_created'],
            $result['orders_skipped'],
        ));
        $this->info(sprintf(
            'Line items created: %d · skipped (no product): %d · skipped (invalid): %d',
            $result['items_created'],
            $result['lines_skipped_no_product'],
            $result['lines_skipped_invalid'],
        ));
        $this->info(sprintf(
            'Stock total before/after: %s / %s (delta %s)',
            number_format($beforeStock, 0, '.', ''),
            number_format($afterStock, 0, '.', ''),
            number_format($afterStock - $beforeStock, 0, '.', ''),
        ));

        if ($result['unmatched_items'] !== []) {
            $this->newLine();
            $this->warn('Top unmatched item codes:');
            foreach (array_slice($result['unmatched_items'], 0, 20, true) as $code => $count) {
                $this->line("  {$code}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
