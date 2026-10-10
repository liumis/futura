<?php

namespace App\Console\Commands;

use App\Services\MayurWarehouseCargoImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportMayurWarehouseCargos extends Command
{
    protected $signature = 'app:import-mayur-warehouse-cargos
                            {--file= : Path to prekės Mayur XLS}
                            {--dry-run : Parse and report without writing}
                            {--create-missing-products : Create missing collection/color/size products, then attach skipped lines}';

    protected $description = 'Import historical Mayur purchases as Received warehouse cargos (no stock change)';

    public function handle(MayurWarehouseCargoImporter $importer): int
    {
        $path = $this->option('file');
        if (! filled($path)) {
            $candidates = [
                storage_path('app/mayur-prekes.xls'),
                'C:\\Users\\liumi\\Downloads\\prekės Mayur 2025-2026.08.31.xls',
                'C:\\Users\\liumi\\Downloads\\prekes Mayur 2025-2026.08.31.xls',
            ];
            foreach (glob('C:\\Users\\liumi\\Downloads\\*Mayur*.xls') ?: [] as $candidate) {
                if (str_starts_with(mb_strtolower(basename($candidate)), 'prek')) {
                    array_unshift($candidates, $candidate);
                }
            }
            $path = collect($candidates)->first(fn (string $file): bool => is_file($file));
        }

        if (! filled($path) || ! is_file($path)) {
            $this->error('Mayur prekės XLS not found. Pass --file=...');

            return self::FAILURE;
        }

        try {
            $result = $importer->import(
                $path,
                (bool) $this->option('dry-run'),
                (bool) $this->option('create-missing-products'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');
        }

        foreach ($result['documents'] as $doc) {
            $this->line(sprintf(
                '  %s date=%s lines=%d rolls=%d',
                $doc['doc'],
                $doc['date'] ?? '—',
                $doc['lines'],
                $doc['rolls'],
            ));
        }

        $this->info(sprintf(
            'Matched lines=%d unmatched=%d · cargos created=%d skipped=%d · items=%d warehouse-imports=%d',
            $result['lines_matched'],
            $result['lines_unmatched'],
            $result['cargos_created'],
            $result['cargos_skipped'],
            $result['items_created'],
            $result['imports_created'],
        ));

        if (($result['products_created'] ?? 0) > 0 || ($result['colors_created'] ?? 0) > 0 || ($result['collections_created'] ?? 0) > 0) {
            $this->info(sprintf(
                'Created collections=%d colors=%d products=%d',
                $result['collections_created'],
                $result['colors_created'],
                $result['products_created'],
            ));
        }

        if ($result['unmatched_samples'] !== []) {
            $this->newLine();
            $this->warn('Unmatched samples:');
            foreach ($result['unmatched_samples'] as $sample) {
                $this->line('  '.$sample);
            }
        }

        return self::SUCCESS;
    }
}
