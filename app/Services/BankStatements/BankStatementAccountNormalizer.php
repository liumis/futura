<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use Illuminate\Support\Facades\DB;

class BankStatementAccountNormalizer
{
    public function __construct(
        private readonly BankStatementImporter $importer,
    ) {}

    /**
     * Map Excel ACC-* keys (and other aliases) to canonical company IBANs.
     *
     * @return array{transactions: int, imports: int}
     */
    public function normalizeExisting(): array
    {
        $txUpdated = 0;
        $importUpdated = 0;

        DB::transaction(function () use (&$txUpdated, &$importUpdated): void {
            $transactions = BankStatementTransaction::query()
                ->whereNotNull('account_iban')
                ->where('account_iban', '!=', '')
                ->orderBy('id')
                ->get();

            foreach ($transactions as $transaction) {
                $canonical = CompanyBankAccount::canonicalizeStatementAccount($transaction->account_iban);
                if ($canonical === null || $canonical === $transaction->account_iban) {
                    continue;
                }

                $payload = [
                    'account_iban' => $canonical,
                    'direction' => $transaction->direction,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'booking_date' => optional($transaction->booking_date)?->toDateString(),
                    'account_servicer_ref' => $transaction->account_servicer_ref,
                    'end_to_end_id' => $transaction->end_to_end_id,
                    'transaction_id' => $transaction->transaction_id,
                    'counterpart_iban' => $transaction->counterpart_iban,
                    'counterpart_name' => $transaction->counterpart_name,
                ];

                $transaction->forceFill([
                    'account_iban' => $canonical,
                    'fingerprint' => $this->importer->fingerprint($payload),
                ])->save();

                $txUpdated++;
            }

            $imports = BankStatementImport::query()
                ->whereNotNull('account_iban')
                ->where('account_iban', '!=', '')
                ->orderBy('id')
                ->get();

            foreach ($imports as $import) {
                $canonical = CompanyBankAccount::canonicalizeStatementAccount($import->account_iban);
                if ($canonical === null || $canonical === $import->account_iban) {
                    continue;
                }

                $import->forceFill(['account_iban' => $canonical])->save();
                $importUpdated++;
            }
        });

        return [
            'transactions' => $txUpdated,
            'imports' => $importUpdated,
        ];
    }
}
