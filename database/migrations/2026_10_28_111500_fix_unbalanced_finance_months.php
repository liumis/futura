<?php

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Models\Invoice;
use App\Services\InvoiceDefaultFinanceLines;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Clean unbalanced-month causes:
 * - July 2026: soft-remove duplicate Excel own-transfer legs that re-import camt credits/debits
 * - August 2026: insert mirror legs for own transfers when the other account statement is missing
 * - September 2026: flip misclassified own-transfer "both credit" pairs to debit+credit
 * - Rebuild broken journals for credit notes FTE0063/FTE0073 and VMI-5018354/VMI-5023867
 *
 * Skipped in testing. Idempotent where practical.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        DB::transaction(function (): void {
            $this->removeJulyDuplicateOwnTransfers();
            $this->fixSeptemberBothCreditPairs();
            $this->mirrorMissingAugustOwnTransferLegs();
            $this->rebuildBrokenInvoiceJournals();
        });
    }

    public function down(): void
    {
        // Data repair — intentionally not reversed.
    }

    private function removeJulyDuplicateOwnTransfers(): void
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-07')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer());

        if ($txs->isEmpty()) {
            return;
        }

        $deleteIds = [];

        foreach ([BankStatementDirection::Credit, BankStatementDirection::Debit] as $direction) {
            $groups = $txs
                ->filter(fn (BankStatementTransaction $tx): bool => $tx->direction === $direction)
                ->groupBy(function (BankStatementTransaction $tx): string {
                    return ($tx->booking_date?->toDateString() ?? '')
                        .'|'.number_format((float) $tx->amount, 2, '.', '')
                        .'|'.Str::upper(preg_replace('/\s+/', '', (string) $tx->account_iban) ?? '');
                });

            foreach ($groups as $group) {
                if ($group->count() < 2) {
                    continue;
                }

                // Prefer the camt/XML-quality row (has counterpart IBAN); drop Excel re-imports.
                $keeper = $group
                    ->sortBy(function (BankStatementTransaction $tx): string {
                        return sprintf(
                            '%d-%010d',
                            filled($tx->counterpart_iban) ? 0 : 1,
                            (int) $tx->getKey(),
                        );
                    })
                    ->first();

                foreach ($group as $tx) {
                    if ((int) $tx->getKey() === (int) $keeper->getKey()) {
                        continue;
                    }
                    // Only remove clear re-imports (empty counterpart, or "NNN · Tarp savo" Excel style).
                    $desc = (string) $tx->description;
                    $isExcelStyle = preg_match('/^\d+\s*[·•].*Tarp savo/u', $desc) === 1
                        || ! filled($tx->counterpart_iban);
                    if (! $isExcelStyle) {
                        continue;
                    }
                    $deleteIds[] = (int) $tx->getKey();
                }
            }
        }

        $deleteIds = array_values(array_unique($deleteIds));
        if ($deleteIds === []) {
            return;
        }

        if (Schema::hasTable('bank_statement_transaction_invoice')) {
            DB::table('bank_statement_transaction_invoice')
                ->whereIn('bank_statement_transaction_id', $deleteIds)
                ->delete();
        }

        BankStatementTransaction::query()->whereIn('id', $deleteIds)->delete();
    }

    private function fixSeptemberBothCreditPairs(): void
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-09')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer())
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->direction === BankStatementDirection::Credit)
            ->values();

        $used = [];

        foreach ($txs as $a) {
            $aId = (int) $a->getKey();
            if (isset($used[$aId])) {
                continue;
            }

            $aIban = $this->canonIban($a->account_iban);
            $aCp = $this->canonIban($a->counterpart_iban);
            if ($aIban === null || $aCp === null) {
                continue;
            }

            $mate = $txs->first(function (BankStatementTransaction $b) use ($a, $aId, $aIban, $aCp, $used): bool {
                $bId = (int) $b->getKey();
                if ($bId === $aId || isset($used[$bId])) {
                    return false;
                }
                if ($a->booking_date?->toDateString() !== $b->booking_date?->toDateString()) {
                    return false;
                }
                if (abs((float) $a->amount - (float) $b->amount) > 0.005) {
                    return false;
                }

                $bIban = $this->canonIban($b->account_iban);
                $bCp = $this->canonIban($b->counterpart_iban);

                return $bIban === $aCp && $bCp === $aIban;
            });

            if ($mate === null) {
                continue;
            }

            // Money received on $a ⇒ mate (source account) must be debit.
            $mate->forceFill([
                'direction' => BankStatementDirection::Debit,
            ])->save();

            $used[$aId] = true;
            $used[(int) $mate->getKey()] = true;
        }
    }

    private function mirrorMissingAugustOwnTransferLegs(): void
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-08')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer());

        if ($txs->isEmpty()) {
            return;
        }

        foreach ($txs as $tx) {
            $account = $this->canonIban($tx->account_iban);
            $counterpart = $this->canonIban($tx->counterpart_iban);
            if ($account === null || $counterpart === null) {
                continue;
            }

            $hasMate = $txs->contains(function (BankStatementTransaction $other) use ($tx, $account, $counterpart): bool {
                if ((int) $other->getKey() === (int) $tx->getKey()) {
                    return false;
                }
                if ($tx->booking_date?->toDateString() !== $other->booking_date?->toDateString()) {
                    return false;
                }
                if (abs((float) $tx->amount - (float) $other->amount) > 0.005) {
                    return false;
                }

                $otherAccount = $this->canonIban($other->account_iban);
                $otherCp = $this->canonIban($other->counterpart_iban);
                if ($otherAccount !== $counterpart) {
                    return false;
                }

                // Prefer explicit mirror IBAN pair; also accept empty counterpart on mate.
                if ($otherCp !== null && $otherCp !== $account) {
                    return false;
                }

                $wantOpposite = $tx->direction === BankStatementDirection::Credit
                    ? BankStatementDirection::Debit
                    : BankStatementDirection::Credit;

                return $other->direction === $wantOpposite;
            });

            if ($hasMate) {
                continue;
            }

            $fingerprint = 'balance-mirror:'.(int) $tx->getKey();
            if (BankStatementTransaction::query()->where('fingerprint', $fingerprint)->exists()) {
                continue;
            }

            $mirrorDirection = $tx->direction === BankStatementDirection::Credit
                ? BankStatementDirection::Debit
                : BankStatementDirection::Credit;

            BankStatementTransaction::query()->create([
                'bank_statement_import_id' => $tx->bank_statement_import_id,
                'fingerprint' => $fingerprint,
                'month_key' => '2026-08',
                'booking_date' => $tx->booking_date,
                'value_date' => $tx->value_date,
                'direction' => $mirrorDirection,
                'amount' => $tx->amount,
                'currency' => $tx->currency ?: 'EUR',
                'account_iban' => $tx->counterpart_iban,
                'bank_name' => $tx->bank_name,
                'counterpart_name' => $tx->counterpart_name ?: 'Futura Textiles Europe, UAB',
                'counterpart_iban' => $tx->account_iban,
                'counterpart_bic' => $tx->counterpart_bic,
                'description' => 'Tarp savo saskaitu (Between account) [auto-mirror for missing statement]',
                'comment' => 'Created by 2026_10_28_111500_fix_unbalanced_finance_months to balance own-account month',
                'end_to_end_id' => $tx->end_to_end_id,
                'no_document_needed' => true,
                'is_historical' => (bool) $tx->is_historical,
            ]);
        }
    }

    private function rebuildBrokenInvoiceJournals(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_finance_lines')) {
            return;
        }

        $numbers = ['FTE0063', 'FTE0073', 'VMI-5018354', 'VMI-5023867'];

        Invoice::query()
            ->whereIn('invoice_number', $numbers)
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice): void {
                InvoiceDefaultFinanceLines::apply($invoice, force: true);
            });
    }

    private function canonIban(?string $iban): ?string
    {
        $iban = Str::upper(preg_replace('/\s+/', '', (string) $iban) ?? '');

        return $iban !== '' ? $iban : null;
    }
};
