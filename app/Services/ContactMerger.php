<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ContactMerger
{
    /**
     * Merge $sourceIds into $targetId. Reassigns invoices/payments/manual_imports, then deletes sources.
     *
     * @param  list<int>  $sourceIds
     * @return array{target_id: int, merged_ids: list<int>, invoices: int, payments: int, manual_imports: int}
     */
    public static function mergeInto(int $targetId, array $sourceIds, bool $preferLongerName = true): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        $sourceIds = array_values(array_filter($sourceIds, fn (int $id): bool => $id !== $targetId && $id > 0));

        if ($sourceIds === []) {
            throw new InvalidArgumentException('Select at least two contacts to merge.');
        }

        $target = Contact::query()->find($targetId);
        if ($target === null) {
            throw new RuntimeException('Target contact #'.$targetId.' was not found.');
        }

        $sources = Contact::query()->whereIn('id', $sourceIds)->get();
        if ($sources->count() !== count($sourceIds)) {
            throw new RuntimeException('One or more contacts to merge were not found.');
        }

        $movedInvoices = 0;
        $movedPayments = 0;
        $movedManual = 0;

        DB::transaction(function () use ($target, $sources, $preferLongerName, &$movedInvoices, &$movedPayments, &$movedManual): void {
            foreach ($sources as $source) {
                self::fillMissingProfileFields($target, $source, $preferLongerName);

                $movedInvoices += DB::table('invoices')
                    ->where('contact_id', $source->getKey())
                    ->update(['contact_id' => $target->getKey()]);

                if (Schema::hasTable('payments')) {
                    $movedPayments += DB::table('payments')
                        ->where('contact_id', $source->getKey())
                        ->update(['contact_id' => $target->getKey()]);
                }

                if (Schema::hasTable('manual_imports')) {
                    $movedManual += DB::table('manual_imports')
                        ->where('contact_id', $source->getKey())
                        ->update(['contact_id' => $target->getKey()]);
                }

                $source->delete();
            }

            $target->save();
        });

        return [
            'target_id' => (int) $target->getKey(),
            'merged_ids' => $sourceIds,
            'invoices' => $movedInvoices,
            'payments' => $movedPayments,
            'manual_imports' => $movedManual,
        ];
    }

    /**
     * Pick the best keeper among contacts and merge the rest into it.
     *
     * @param  list<int>  $contactIds
     * @return array{target_id: int, merged_ids: list<int>, invoices: int, payments: int, manual_imports: int}
     */
    public static function mergeGroup(array $contactIds): array
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        if (count($contactIds) < 2) {
            throw new InvalidArgumentException('Select at least two contacts to merge.');
        }

        $contacts = Contact::query()
            ->withCount('invoices')
            ->whereIn('id', $contactIds)
            ->get();

        if ($contacts->count() < 2) {
            throw new RuntimeException('Need at least two existing contacts to merge.');
        }

        $target = $contacts
            ->sortByDesc(fn (Contact $c): array => [
                (int) ($c->invoices_count ?? 0),
                mb_strlen((string) $c->company_name),
                str_starts_with((string) $c->company_id, 'AUTO-') ? 0 : 1,
                (int) $c->getKey(),
            ])
            ->first();

        $sources = $contacts
            ->reject(fn (Contact $c): bool => (int) $c->getKey() === (int) $target->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return self::mergeInto((int) $target->getKey(), $sources);
    }

    /**
     * Find contacts that look like the same company as $needle (prefix / punctuation-insensitive).
     *
     * @return list<Contact>
     */
    public static function findSimilar(string $needle): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $normNeedle = self::normalizeName($needle);
        if ($normNeedle === '') {
            return [];
        }

        return Contact::query()
            ->orderBy('id')
            ->get()
            ->filter(function (Contact $contact) use ($needle, $normNeedle): bool {
                $name = trim((string) $contact->company_name);
                if ($name === '') {
                    return false;
                }

                if (Str::lower($name) === Str::lower($needle)) {
                    return true;
                }

                if (str_contains(Str::lower($name), Str::lower($needle))) {
                    return true;
                }

                $norm = self::normalizeName($name);
                if ($norm === '' || $normNeedle === '') {
                    return false;
                }

                if ($norm === $normNeedle) {
                    return true;
                }

                $minLen = min(12, strlen($normNeedle), strlen($norm));
                if ($minLen >= 8) {
                    return str_starts_with($norm, $normNeedle)
                        || str_starts_with($normNeedle, $norm)
                        || str_contains($norm, $normNeedle)
                        || str_contains($normNeedle, $norm);
                }

                return false;
            })
            ->values()
            ->all();
    }

    public static function normalizeName(string $name): string
    {
        $name = Str::lower(trim($name));
        $name = str_replace(['m.b.h.', 'm.b.h', 'mbh'], 'mbh', $name);

        return preg_replace('/[^a-z0-9]+/u', '', $name) ?? '';
    }

    /**
     * Best existing contact for a new/OCR name (exact, then prefix/truncation).
     */
    public static function matchExisting(?string $name): ?Contact
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $lower = Str::lower($name);
        $norm = self::normalizeName($name);

        $exact = Contact::query()
            ->whereRaw('LOWER(company_name) = ?', [$lower])
            ->first();
        if ($exact !== null) {
            return $exact;
        }

        if ($norm === '' || strlen($norm) < 8) {
            return null;
        }

        $candidates = Contact::query()
            ->whereRaw('LOWER(company_name) like ?', ['%'.mb_substr($name, 0, 12).'%'])
            ->orderBy('id')
            ->get();

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $candidateNorm = self::normalizeName((string) $candidate->company_name);
            if ($candidateNorm === '') {
                continue;
            }

            $score = 0;
            if ($candidateNorm === $norm) {
                $score = 1000 + strlen($candidateNorm);
            } elseif (strlen($norm) >= 12 && str_starts_with($candidateNorm, $norm)) {
                // New name is truncation of existing → reuse existing
                $score = 800 + strlen($candidateNorm);
            } elseif (strlen($candidateNorm) >= 12 && str_starts_with($norm, $candidateNorm)) {
                // Existing is truncation of new → reuse and caller may rename
                $score = 700 + strlen($norm);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function fillMissingProfileFields(Contact $target, Contact $source, bool $preferLongerName): void
    {
        $fields = [
            'company_email',
            'company_phone',
            'company_address',
            'company_vat',
            'company_country',
            'contact_name',
            'contact_email',
            'contact_phone',
            'bank_account',
            'bank_bic',
            'default_finance_type',
        ];

        foreach ($fields as $field) {
            if (! filled($target->{$field}) && filled($source->{$field})) {
                $target->{$field} = $source->{$field};
            }
        }

        $targetName = trim((string) $target->company_name);
        $sourceName = trim((string) $source->company_name);
        if ($preferLongerName && mb_strlen($sourceName) > mb_strlen($targetName)) {
            $target->company_name = $sourceName;
        }

        $targetId = (string) $target->company_id;
        $sourceId = (string) $source->company_id;
        if (
            (str_starts_with($targetId, 'AUTO-') || $targetId === '')
            && $sourceId !== ''
            && ! str_starts_with($sourceId, 'AUTO-')
        ) {
            $target->company_id = $sourceId;
        }

        if (! $target->private_provider && $source->private_provider) {
            $target->private_provider = true;
        }
    }
}
