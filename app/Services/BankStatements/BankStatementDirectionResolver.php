<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Enums\CompanyBankAccountType;
use App\Models\CompanyBankAccount;

class BankStatementDirectionResolver
{
    /**
     * Resolve Income vs Outcome using company bank accounts.
     *
     * Rules:
     * - Transfer between the company's own accounts → keep bank cash direction (not P&amp;L).
     * - Transfer to an account outside the company set → Expense (Debit).
     * - Transfer from an account outside the company set → Income (Credit).
     * - When counterpart IBAN is missing, trust the file direction and account purpose.
     */
    public static function resolve(
        BankStatementDirection $fromFile,
        ?string $accountIban,
        ?string $counterpartIban = null,
        ?string $counterpartName = null,
        ?string $description = null,
        ?string $endToEndId = null,
        ?string $comment = null,
    ): BankStatementDirection {
        if (self::isOwnTransfer($accountIban, $counterpartIban, $counterpartName, $description, $endToEndId, $comment)) {
            return $fromFile;
        }

        // Known external counterpart IBAN: money out = expense, money in = income.
        if (filled($counterpartIban) && ! CompanyBankAccount::isOwnAccountIdentifier($counterpartIban)) {
            return $fromFile === BankStatementDirection::Debit
                ? BankStatementDirection::Debit
                : BankStatementDirection::Credit;
        }

        $account = CompanyBankAccount::resolveFromStatementAccount($accountIban);
        $type = $account?->type;

        // No counterpart IBAN (typical Excel). File operation is the cash direction;
        // expense/card accounts still treat outbound as expense.
        if ($type === CompanyBankAccountType::Expense || $type === CompanyBankAccountType::CreditCard) {
            if ($fromFile === BankStatementDirection::Debit) {
                return BankStatementDirection::Debit;
            }

            // Inbound on expense/card: keep as income only for refunds/top-ups or leave as file Credit.
            return BankStatementDirection::Credit;
        }

        return $fromFile;
    }

    public static function isOwnTransfer(
        ?string $accountIban,
        ?string $counterpartIban = null,
        ?string $counterpartName = null,
        ?string $description = null,
        ?string $endToEndId = null,
        ?string $comment = null,
    ): bool {
        if (CompanyBankAccount::isOwnAccountIdentifier($counterpartIban)) {
            $own = CompanyBankAccount::canonicalizeStatementAccount($accountIban);
            $counterpart = CompanyBankAccount::canonicalizeStatementAccount($counterpartIban);

            if ($own === null || $counterpart === null || $own !== $counterpart) {
                return true;
            }
        }

        $hay = self::normalizedHaystack($counterpartName, $description, $endToEndId, $comment);
        if ($hay === '') {
            return false;
        }

        if (str_contains($hay, 'tarp savo')
            || str_contains($hay, 'between account')
            || str_contains($hay, 'between your account')
            || str_contains($hay, 'perkelimas tarp')
            || str_contains($hay, 'payment between your acc')
            || str_contains($hay, 'lesu grazin')) {
            return true;
        }

        // Counterpart column renamed to another own account (Income / Credit card / Expense).
        if (CompanyBankAccount::resolveFromPartyName($counterpartName) !== null) {
            return true;
        }

        // SEB/accounting short labels for moves between own accounts (no IBAN on Excel).
        return self::isGenericOwnTransferLabel($counterpartName)
            || self::isGenericOwnTransferLabel($description);
    }

    /**
     * @return list<string>
     */
    public static function ownTransferLikePatterns(): array
    {
        return [
            '%Tarp savo%',
            '%Tarp sąvo%',
            '%Between account%',
            '%Between your account%',
            '%perkėlimas tarp%',
            '%perkelimas tarp%',
            '%payment between your acc%',
            '%Lėšų grąžinim%',
            '%Lesu grazinim%',
            '%Lėsu grąžinim%',
        ];
    }

    /**
     * @return list<string>
     */
    public static function genericOwnTransferLabels(): array
    {
        return [
            'pavedimas',
            'pervedimas',
            'pav',
            'pervedimas tarp saskaitu',
            'pavedimas tarp saskaitu',
        ];
    }

    /**
     * Bank fees and service-plan charges documented by the statement file itself.
     */
    public static function isBankServiceFee(
        ?string $counterpartName = null,
        ?string $description = null,
        ?string $endToEndId = null,
        ?string $comment = null,
    ): bool {
        $hay = self::normalizedHaystack($counterpartName, $description, $endToEndId, $comment);
        if ($hay === '') {
            return false;
        }

        foreach (self::bankServiceFeeNeedles() as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function bankServiceFeeNeedles(): array
    {
        return [
            'bank fee',
            'banko paslaug',
            'korteles naudojimo',
            'kasdienis',
            'paslaugu planas',
            'grupini pinig',
            'mokestis',
        ];
    }

    /**
     * @return list<string>
     */
    public static function bankServiceFeeLikePatterns(): array
    {
        return [
            '%bank fee%',
            '%Banko paslaug%',
            '%Kortelės naudojimo%',
            '%Korteles naudojimo%',
            '%KASDIENIS%',
            '%paslaugų planas%',
            '%paslaugu planas%',
            '%grupinį pinig%',
            '%grupini pinig%',
            '%Mokestis%',
        ];
    }

    public static function looksLikeRefundOrTopUp(
        ?string $counterpartName = null,
        ?string $description = null,
        ?string $endToEndId = null,
        ?string $comment = null,
    ): bool {
        $hay = self::normalizedHaystack($counterpartName, $description, $endToEndId, $comment);

        return str_contains($hay, 'refund')
            || str_contains($hay, 'grazin')
            || str_contains($hay, 'grąžin')
            || str_contains($hay, 'lesu grazin')
            || str_contains($hay, 'permoka')
            || str_contains($hay, 'top up')
            || str_contains($hay, 'topup')
            || str_contains($hay, 'korteles papild')
            || str_contains($hay, 'card top');
    }

    private static function normalizedHaystack(
        ?string $counterpartName = null,
        ?string $description = null,
        ?string $endToEndId = null,
        ?string $comment = null,
    ): string {
        $hay = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($description ?? ''),
            (string) ($comment ?? ''),
            (string) ($counterpartName ?? ''),
            (string) ($endToEndId ?? ''),
        ]))));

        if ($hay === '') {
            return '';
        }

        return strtr($hay, [
            'ą' => 'a',
            'č' => 'c',
            'ę' => 'e',
            'ė' => 'e',
            'į' => 'i',
            'š' => 's',
            'ų' => 'u',
            'ū' => 'u',
            'ž' => 'z',
        ]);
    }

    public static function isGenericOwnTransferLabel(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        // Strip leading op numbers like "302 · Pavedimas" / "321 · Pav"
        $value = preg_replace('/^\s*\S+\s*·\s*/u', '', (string) $value) ?? (string) $value;
        $normalized = self::normalizedHaystack($value);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        return in_array($normalized, self::genericOwnTransferLabels(), true);
    }
}
