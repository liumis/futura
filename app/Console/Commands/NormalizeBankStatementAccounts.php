<?php

namespace App\Console\Commands;

use App\Services\BankStatements\BankStatementAccountNormalizer;
use App\Services\CompanyBankAccountSyncer;
use Illuminate\Console\Command;

class NormalizeBankStatementAccounts extends Command
{
    protected $signature = 'bank-statements:normalize-accounts';

    protected $description = 'Map Excel ACC-* account keys to canonical company IBANs and refresh statement aliases';

    public function handle(
        CompanyBankAccountSyncer $syncer,
        BankStatementAccountNormalizer $normalizer,
    ): int {
        $syncer->syncFromKnownSources();
        $result = $normalizer->normalizeExisting();

        $this->info("Updated {$result['transactions']} transactions and {$result['imports']} imports.");

        return self::SUCCESS;
    }
}
