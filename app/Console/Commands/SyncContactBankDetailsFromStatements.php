<?php

namespace App\Console\Commands;

use App\Services\ContactBankDetailsFromStatements;
use Illuminate\Console\Command;

class SyncContactBankDetailsFromStatements extends Command
{
    protected $signature = 'contacts:sync-bank-from-statements
                            {--dry-run : Show updates without writing}
                            {--overwrite : Overwrite existing bank_account / bank_bic}';

    protected $description = 'Fill contact IBAN/BIC from previous bank statement counterpart details';

    public function handle(ContactBankDetailsFromStatements $syncer): int
    {
        $result = $syncer->sync(
            dryRun: (bool) $this->option('dry-run'),
            onlyMissing: ! (bool) $this->option('overwrite'),
        );

        foreach ($result['changes'] as $change) {
            $this->line(sprintf(
                '#%d %s ← %s%s',
                $change['contact_id'],
                $change['company_name'],
                $change['bank_account'],
                filled($change['bank_bic'] ?? null) ? ' / '.$change['bank_bic'] : '',
            ));
            $this->line('    source: '.$change['source']);
        }

        $this->newLine();
        $this->info(sprintf(
            '%sUpdated: %d; skipped: %d; unmatched counterpart IBANs: %d',
            $this->option('dry-run') ? '[dry-run] ' : '',
            $result['updated'],
            $result['skipped'],
            $result['unmatched'],
        ));

        return self::SUCCESS;
    }
}
