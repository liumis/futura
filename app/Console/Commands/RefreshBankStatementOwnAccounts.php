<?php

namespace App\Console\Commands;

use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Services\BankStatements\BankStatementDirectionResolver;
use App\Services\BankStatements\BankStatementImporter;
use Illuminate\Console\Command;

class RefreshBankStatementOwnAccounts extends Command
{
    protected $signature = 'bank-statements:refresh-own-accounts {--dry-run : Show counts without saving}';

    protected $description = 'Re-detect own-account transfers and bank fees, and correct Income/Outcome';

    public function handle(BankStatementImporter $importer): int
    {
        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);

        $markedOwn = 0;
        $markedFees = 0;
        $directionFixed = 0;
        $unchanged = 0;

        $query = BankStatementTransaction::query()->orderBy('id');

        foreach ($query->cursor() as $transaction) {
            $fromFile = $transaction->direction;
            $resolved = BankStatementDirectionResolver::resolve(
                $fromFile,
                $transaction->account_iban,
                $transaction->counterpart_iban,
                $transaction->counterpart_name,
                $transaction->description,
                $transaction->end_to_end_id,
                $transaction->comment,
            );

            $isOwn = BankStatementDirectionResolver::isOwnTransfer(
                $transaction->account_iban,
                $transaction->counterpart_iban,
                $transaction->counterpart_name,
                $transaction->description,
                $transaction->end_to_end_id,
                $transaction->comment,
            );

            $isFee = BankStatementDirectionResolver::isBankServiceFee(
                $transaction->counterpart_name,
                $transaction->description,
                $transaction->end_to_end_id,
                $transaction->comment,
            );

            $dirty = false;
            $payload = [];

            if (($isOwn || $isFee) && ! $transaction->no_document_needed) {
                $payload['no_document_needed'] = true;
                if ($isOwn) {
                    $markedOwn++;
                } else {
                    $markedFees++;
                }
                $dirty = true;
            }

            if ($resolved !== $fromFile) {
                $payload['direction'] = $resolved;
                $directionFixed++;
                $dirty = true;
            }

            if (! $dirty) {
                $unchanged++;

                continue;
            }

            if ($this->option('dry-run')) {
                continue;
            }

            if (isset($payload['direction'])) {
                $transaction->direction = $resolved;
                $payload['fingerprint'] = $importer->fingerprint([
                    'account_iban' => $transaction->account_iban,
                    'account_servicer_ref' => $transaction->account_servicer_ref,
                    'end_to_end_id' => $transaction->end_to_end_id,
                    'transaction_id' => $transaction->transaction_id,
                    'booking_date' => optional($transaction->booking_date)?->toDateString(),
                    'direction' => $resolved,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'counterpart_iban' => $transaction->counterpart_iban,
                    'counterpart_name' => $transaction->counterpart_name,
                ]);
            }

            $transaction->forceFill($payload)->save();
        }

        $prefix = $this->option('dry-run') ? '[dry-run] ' : '';
        $this->info("{$prefix}Own-account flags added: {$markedOwn}; bank-fee flags added: {$markedFees}; directions fixed: {$directionFixed}; unchanged: {$unchanged}.");

        return self::SUCCESS;
    }
}
