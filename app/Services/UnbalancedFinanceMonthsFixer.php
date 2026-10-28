<?php

namespace App\Services;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent repair for dashboard "unbalanced months" (own-account + journals).
 * Safe to run after prod bootstrap import (which otherwise reloads the dirty dump).
 */
final class UnbalancedFinanceMonthsFixer
{
    /**
     * @return array{july_deleted: int, september_flipped: int, august_mirrored: int, journals_rebuilt: int}
     */
    public static function run(): array
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return [
                'july_deleted' => 0,
                'september_flipped' => 0,
                'august_mirrored' => 0,
                'journals_rebuilt' => 0,
            ];
        }

        $stats = [
            'july_deleted' => 0,
            'september_flipped' => 0,
            'august_mirrored' => 0,
            'journals_rebuilt' => 0,
        ];

        DB::transaction(function () use (&$stats): void {
            $stats['july_deleted'] = self::removeJulyDuplicateOwnTransfers();
            $stats['september_flipped'] = self::fixSeptemberBothCreditPairs();
            $stats['august_mirrored'] = self::mirrorMissingAugustOwnTransferLegs();
            $stats['journals_rebuilt'] = self::rebuildBrokenInvoiceJournals();
        });

        return $stats;
    }

    private static function removeJulyDuplicateOwnTransfers(): int
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-07')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer());

        if ($txs->isEmpty()) {
            return 0;
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
            return 0;
        }

        if (Schema::hasTable('bank_statement_transaction_invoice')) {
            DB::table('bank_statement_transaction_invoice')
                ->whereIn('bank_statement_transaction_id', $deleteIds)
                ->delete();
        }

        return BankStatementTransaction::query()->whereIn('id', $deleteIds)->delete();
    }

    private static function fixSeptemberBothCreditPairs(): int
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-09')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer())
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->direction === BankStatementDirection::Credit)
            ->values();

        $used = [];
        $flipped = 0;

        foreach ($txs as $a) {
            $aId = (int) $a->getKey();
            if (isset($used[$aId])) {
                continue;
            }

            $aIban = self::canonIban($a->account_iban);
            $aCp = self::canonIban($a->counterpart_iban);
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

                $bIban = self::canonIban($b->account_iban);
                $bCp = self::canonIban($b->counterpart_iban);

                return $bIban === $aCp && $bCp === $aIban;
            });

            if ($mate === null) {
                continue;
            }

            $mate->forceFill([
                'direction' => BankStatementDirection::Debit,
            ])->save();

            $used[$aId] = true;
            $used[(int) $mate->getKey()] = true;
            $flipped++;
        }

        return $flipped;
    }

    private static function mirrorMissingAugustOwnTransferLegs(): int
    {
        $txs = BankStatementTransaction::query()
            ->where('month_key', '2026-08')
            ->orderBy('id')
            ->get()
            ->filter(fn (BankStatementTransaction $tx): bool => $tx->isInternalTransfer());

        if ($txs->isEmpty()) {
            return 0;
        }

        $created = 0;

        foreach ($txs as $tx) {
            $account = self::canonIban($tx->account_iban);
            $counterpart = self::canonIban($tx->counterpart_iban);
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

                $otherAccount = self::canonIban($other->account_iban);
                $otherCp = self::canonIban($other->counterpart_iban);
                if ($otherAccount !== $counterpart) {
                    return false;
                }

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
                'comment' => 'Created by UnbalancedFinanceMonthsFixer to balance own-account month',
                'end_to_end_id' => $tx->end_to_end_id,
                'no_document_needed' => true,
                'is_historical' => (bool) $tx->is_historical,
            ]);
            $created++;
        }

        return $created;
    }

    private static function rebuildBrokenInvoiceJournals(): int
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_finance_lines')) {
            return 0;
        }

        $numbers = ['FTE0063', 'FTE0073', 'VMI-5018354', 'VMI-5023867'];
        $rebuilt = 0;

        Invoice::query()
            ->whereIn('invoice_number', $numbers)
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice) use (&$rebuilt): void {
                InvoiceDefaultFinanceLines::apply($invoice, force: true);
                $rebuilt++;
            });

        return $rebuilt;
    }

    private static function canonIban(?string $iban): ?string
    {
        $iban = Str::upper(preg_replace('/\s+/', '', (string) $iban) ?? '');

        return $iban !== '' ? $iban : null;
    }
}
