<?php

namespace App\Services;

use App\Models\Contact;
use App\Support\ExtractedTextNormalizer;
use Illuminate\Support\Collection;

class UtfMojibakeRepairer
{
    /**
     * Contact columns that often pick up CP437/OEM mojibake from OCR/imports.
     *
     * @var list<string>
     */
    public const CONTACT_FIELDS = [
        'company_name',
        'company_address',
        'contact_name',
    ];

    /**
     * @return array{
     *     updated: int,
     *     changes: list<array{id: int, field: string, from: string, to: string}>
     * }
     */
    public static function repairContacts(?Collection $contacts = null, bool $dryRun = false): array
    {
        $contacts ??= Contact::query()->orderBy('id')->get();
        $changes = [];
        $updated = 0;

        foreach ($contacts as $contact) {
            if (! $contact instanceof Contact) {
                continue;
            }

            $patch = [];

            foreach (self::CONTACT_FIELDS as $field) {
                $from = trim((string) ($contact->getAttribute($field) ?? ''));
                if ($from === '' || ! ExtractedTextNormalizer::looksCorrupted($from)) {
                    continue;
                }

                $to = self::repairValue($from, $field === 'company_name');
                if ($to === null || $to === '' || $to === $from) {
                    continue;
                }

                $patch[$field] = $to;
                $changes[] = [
                    'id' => (int) $contact->getKey(),
                    'field' => $field,
                    'from' => $from,
                    'to' => $to,
                ];
            }

            if ($patch === []) {
                continue;
            }

            if (! $dryRun) {
                $contact->forceFill($patch)->save();
                $updated++;
            }
        }

        return [
            'updated' => $dryRun ? count(array_unique(array_column($changes, 'id'))) : $updated,
            'changes' => $changes,
        ];
    }

    public static function repairValue(string $raw, bool $lithuanianCompanyName = false): ?string
    {
        // Only the safe CP437 glyph→byte remap — full PDF normalize can invent junk.
        $repaired = ExtractedTextNormalizer::repairCp437Mojibake($raw);

        if (! is_string($repaired) || $repaired === '' || $repaired === $raw) {
            return null;
        }

        if (ExtractedTextNormalizer::looksCorrupted($repaired)) {
            return null;
        }

        if ($lithuanianCompanyName && LithuanianCompanyNameNormalizer::extract($repaired)['form'] !== null) {
            $normalized = LithuanianCompanyNameNormalizer::normalize($repaired);
            if ($normalized !== '') {
                $repaired = $normalized;
            }
        }

        return $repaired;
    }
}
