<?php

namespace App\Console\Commands;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Services\BankStatements\BankStatementCounterpartResolver;
use App\Services\BankStatements\BankStatementDescriptionCleaner;
use Illuminate\Console\Command;

class FixBankStatementCounterpartNames extends Command
{
    protected $signature = 'bank-statements:fix-counterpart-names
                            {--dry-run : Show changes without writing}
                            {--limit=0 : Max rows to process (0 = all)}';

    protected $description = 'Clean noisy counterpart_name and description values on bank statement transactions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = BankStatementTransaction::query()->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $updated = 0;
        $unchanged = 0;
        $fingerprintSkipped = 0;

        $query->chunkById(200, function ($rows) use ($dryRun, &$updated, &$unchanged, &$fingerprintSkipped): void {
            foreach ($rows as $row) {
                /** @var BankStatementTransaction $row */
                $originalCounterpart = $row->counterpart_name;
                $cleanedCounterpart = BankStatementCounterpartResolver::resolve(
                    $originalCounterpart,
                    $row->description,
                    $row->end_to_end_id,
                    $row,
                );
                if ($cleanedCounterpart === null && filled($originalCounterpart)) {
                    $cleanedCounterpart = $originalCounterpart;
                }

                $originalDescription = $row->description;
                $cleanedDescription = BankStatementDescriptionCleaner::clean(
                    $originalDescription,
                    $cleanedCounterpart ?? $originalCounterpart,
                );

                $counterpartChanged = filled($originalCounterpart)
                    && $cleanedCounterpart !== null
                    && $cleanedCounterpart !== $originalCounterpart;
                $descriptionChanged = (string) ($cleanedDescription ?? '') !== (string) ($originalDescription ?? '');

                if (! $counterpartChanged && ! $descriptionChanged) {
                    $unchanged++;

                    continue;
                }

                $nextCounterpart = $counterpartChanged ? $cleanedCounterpart : $originalCounterpart;
                $fingerprintUpdate = $row->fingerprint;

                if ($counterpartChanged) {
                    $payload = [
                        'counterpart_name' => $nextCounterpart,
                        'direction' => $row->direction,
                        'amount' => $row->amount,
                        'currency' => $row->currency,
                        'account_iban' => $row->account_iban,
                        'booking_date' => optional($row->booking_date)?->toDateString() ?? (string) $row->booking_date,
                        'account_servicer_ref' => $row->account_servicer_ref,
                        'end_to_end_id' => $row->end_to_end_id,
                        'transaction_id' => $row->transaction_id,
                        'counterpart_iban' => $row->counterpart_iban,
                    ];

                    $newFingerprint = $this->fingerprint($payload);
                    $fingerprintUpdate = $newFingerprint;

                    if ($newFingerprint !== $row->fingerprint) {
                        $collision = BankStatementTransaction::query()
                            ->where('fingerprint', $newFingerprint)
                            ->whereKeyNot($row->getKey())
                            ->exists();

                        if ($collision) {
                            $fingerprintUpdate = $row->fingerprint;
                            $fingerprintSkipped++;
                        }
                    }
                }

                $parts = [];
                if ($counterpartChanged) {
                    $parts[] = 'counterpart: '.$this->short((string) $originalCounterpart).' → '.$this->short((string) $nextCounterpart);
                }
                if ($descriptionChanged) {
                    $parts[] = 'description: '.$this->short((string) ($originalDescription ?? '')).' → '.$this->short((string) ($cleanedDescription ?? ''));
                }

                $this->line(sprintf('#%d: %s', $row->getKey(), implode(' | ', $parts)));

                if (! $dryRun) {
                    $row->forceFill([
                        'counterpart_name' => $nextCounterpart,
                        'description' => $cleanedDescription,
                        'fingerprint' => $fingerprintUpdate,
                    ])->save();
                }

                $updated++;
            }
        });

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Updated: {$updated}; unchanged: {$unchanged}; fingerprint collisions kept: {$fingerprintSkipped}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function fingerprint(array $transaction): string
    {
        $direction = $transaction['direction'] instanceof BankStatementDirection
            ? $transaction['direction']->value
            : (string) $transaction['direction'];

        return hash('sha256', implode('|', [
            strtoupper((string) ($transaction['account_iban'] ?? '')),
            (string) ($transaction['account_servicer_ref'] ?? ''),
            (string) ($transaction['end_to_end_id'] ?? ''),
            (string) ($transaction['transaction_id'] ?? ''),
            (string) ($transaction['booking_date'] ?? ''),
            $direction,
            number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
            strtoupper((string) ($transaction['currency'] ?? 'EUR')),
            strtoupper((string) ($transaction['counterpart_iban'] ?? '')),
            mb_strtoupper((string) ($transaction['counterpart_name'] ?? '')),
        ]));
    }

    private function short(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 87).'...' : $value;
    }
}
