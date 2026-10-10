<?php

namespace App\Console\Commands;

use App\Models\BankStatementImport;
use App\Services\BankStatementDocumentGenerator;
use Illuminate\Console\Command;

class SyncBankStatementDocuments extends Command
{
    protected $signature = 'bank-statements:sync-documents {--import=* : Optional import ID(s) to sync}';

    protected $description = 'Create or refresh Documents (Banko išrašas) for bank statement imports that already have SharePoint files';

    public function handle(): int
    {
        $query = BankStatementImport::query()
            ->where(function ($q): void {
                $q->whereNotNull('sharepoint_item_id')
                    ->orWhereNotNull('sharepoint_web_url')
                    ->orWhereNotNull('sharepoint_path');
            })
            ->orderBy('id');

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('import'))));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($query->cursor() as $import) {
            /** @var BankStatementImport $import */
            if (! $import->hasSharepointLink()) {
                $skipped++;

                continue;
            }

            $hadDocument = filled($import->document_id);
            $document = BankStatementDocumentGenerator::syncForImport($import, $import->uploadedUser);

            if ($document === null) {
                $skipped++;

                continue;
            }

            if ($hadDocument) {
                $updated++;
                $this->line("Updated document #{$document->getKey()} for import #{$import->getKey()}");
            } else {
                $created++;
                $this->info("Created document #{$document->getKey()} for import #{$import->getKey()}");
            }
        }

        $this->newLine();
        $this->info("Done. Created: {$created}, updated: {$updated}, skipped: {$skipped}");

        return self::SUCCESS;
    }
}
