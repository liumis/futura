<?php

namespace App\Services;

use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Services\BankStatements\BankStatementCounterpartCleaner;
use App\Services\BankStatements\BankStatementInvoiceCreator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContactBankDetailsFromStatements
{
    /**
     * @return array{
     *     updated: int,
     *     skipped: int,
     *     unmatched: int,
     *     changes: list<array{contact_id: int, company_name: string, bank_account: string, bank_bic: ?string, source: string}>
     * }
     */
    public function sync(bool $dryRun = false, bool $onlyMissing = true): array
    {
        $candidates = $this->candidateIbanByContact();

        $updated = 0;
        $skipped = 0;
        $changes = [];

        foreach ($candidates as $contactId => $payload) {
            $contact = Contact::query()->find($contactId);
            if ($contact === null) {
                continue;
            }

            $iban = (string) $payload['iban'];
            $bic = filled($payload['bic'] ?? null) ? (string) $payload['bic'] : null;

            $currentIban = $this->normalizeIban((string) ($contact->bank_account ?? ''));
            $currentBic = $this->normalizeBic((string) ($contact->bank_bic ?? ''));

            $fillIban = $iban !== '' && (
                ! $onlyMissing
                || $currentIban === ''
                || $currentIban === '-'
            );
            $fillBic = $bic !== null && $bic !== '' && (
                ! $onlyMissing
                || $currentBic === ''
            );

            if (! $fillIban && ! $fillBic) {
                $skipped++;

                continue;
            }

            // Don't overwrite a different existing IBAN when onlyMissing.
            if ($onlyMissing && $currentIban !== '' && $currentIban !== '-' && $currentIban !== $iban) {
                $skipped++;

                continue;
            }

            $patch = [];
            if ($fillIban) {
                $patch['bank_account'] = $iban;
            }
            if ($fillBic && ($currentBic === '' || ! $onlyMissing || $fillIban)) {
                $patch['bank_bic'] = $bic;
            }

            if ($patch === []) {
                $skipped++;

                continue;
            }

            $changes[] = [
                'contact_id' => (int) $contact->id,
                'company_name' => (string) $contact->company_name,
                'bank_account' => $patch['bank_account'] ?? $currentIban,
                'bank_bic' => $patch['bank_bic'] ?? ($currentBic !== '' ? $currentBic : null),
                'source' => (string) $payload['source'],
            ];

            if (! $dryRun) {
                $contact->forceFill($patch)->save();
            }

            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'unmatched' => $this->unmatchedCount(),
            'changes' => $changes,
        ];
    }

    /**
     * @return array<int, array{iban: string, bic: ?string, source: string, score: int}>
     */
    private function candidateIbanByContact(): array
    {
        /** @var Collection<int, BankStatementTransaction> $rows */
        $rows = BankStatementTransaction::query()
            ->whereNotNull('counterpart_iban')
            ->where('counterpart_iban', '!=', '')
            ->with(['invoice:id,contact_id'])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'counterpart_name',
                'counterpart_iban',
                'counterpart_bic',
                'invoice_id',
                'booking_date',
            ]);

        $best = [];
        /** @var array<string, array<string, int>> $bicVotes iban => [bic => count] */
        $bicVotes = [];

        foreach ($rows as $row) {
            $iban = $this->normalizeIban((string) $row->counterpart_iban);
            if ($iban === '' || CompanyBankAccount::isOwnAccountIdentifier($iban)) {
                continue;
            }

            $bic = $this->normalizeBic((string) ($row->counterpart_bic ?? ''));
            if ($bic !== '') {
                $bicVotes[$iban][$bic] = ($bicVotes[$iban][$bic] ?? 0) + 1;
            }

            $contact = $this->resolveContact($row);
            if ($contact === null) {
                continue;
            }

            $score = 1;
            if ($row->invoice_id && (int) ($row->invoice?->contact_id) === (int) $contact->id) {
                $score += 5;
            }
            if ($bic !== '') {
                $score += 1;
            }

            $contactId = (int) $contact->id;
            $existing = $best[$contactId] ?? null;
            if ($existing !== null && $existing['score'] >= $score) {
                continue;
            }

            $best[$contactId] = [
                'iban' => $iban,
                'bic' => $bic !== '' ? $bic : null,
                'source' => trim((string) ($row->counterpart_name ?? '')).' · tx #'.$row->id,
                'score' => $score,
            ];
        }

        foreach ($best as $contactId => $payload) {
            $iban = $payload['iban'];
            $votes = $bicVotes[$iban] ?? [];
            if ($votes !== []) {
                arsort($votes);
                $best[$contactId]['bic'] = array_key_first($votes);
            }
        }

        return $best;
    }

    private function resolveContact(BankStatementTransaction $row): ?Contact
    {
        $cleaned = BankStatementCounterpartCleaner::clean($row->counterpart_name)
            ?? trim((string) ($row->counterpart_name ?? ''));

        if ($cleaned === '' || $this->isGovernmentOrBankParty($cleaned)) {
            return null;
        }

        $byName = BankStatementInvoiceCreator::matchContact($cleaned)
            ?? ContactMerger::matchExisting($cleaned);

        if ($byName !== null) {
            return $byName;
        }

        // Fallback: normalized legal-form names like "Vinilas, UAB".
        $normalized = LithuanianCompanyNameNormalizer::normalize($cleaned);
        if ($normalized !== null && $normalized !== $cleaned) {
            $byNormalized = ContactMerger::matchExisting($normalized);
            if ($byNormalized !== null) {
                return $byNormalized;
            }
        }

        // Do not fall back to invoice.contact_id — tax/salary lines are often linked
        // to the wrong company and would copy VMI/Sodra IBANs onto suppliers.
        return null;
    }

    private function isGovernmentOrBankParty(string $name): bool
    {
        $n = mb_strtolower($name);

        return str_contains($n, 'valstybin')
            || str_contains($n, 'mokesčių')
            || str_contains($n, 'mokesciu')
            || str_contains($n, 'vsdfv')
            || str_contains($n, 'sodra')
            || str_contains($n, 'vmi ')
            || str_starts_with($n, 'vmi')
            || str_contains($n, 'seb bank')
            || str_contains($n, 'swedbank')
            || str_contains($n, 'luminor');
    }

    private function unmatchedCount(): int
    {
        return BankStatementTransaction::query()
            ->whereNotNull('counterpart_iban')
            ->where('counterpart_iban', '!=', '')
            ->get(['counterpart_name', 'counterpart_iban'])
            ->filter(function (BankStatementTransaction $row): bool {
                $iban = $this->normalizeIban((string) $row->counterpart_iban);
                if ($iban === '' || CompanyBankAccount::isOwnAccountIdentifier($iban)) {
                    return false;
                }

                return $this->resolveContact($row) === null;
            })
            ->count();
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    private function normalizeBic(string $bic): string
    {
        $bic = strtoupper(preg_replace('/\s+/', '', $bic) ?? '');

        if ($bic === '' || Str::startsWith($bic, 'LT') || strlen($bic) < 8) {
            return '';
        }

        return $bic;
    }
}
