<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactComment;
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

    /**
     * Repair CRM board comments and company names (d─ùl → dėl).
     *
     * @return array{
     *     updated: int,
     *     changes: list<array{id: int, field: string, from: string, to: string}>
     * }
     */
    public static function repairDirectoryContacts(?Collection $comments = null, bool $dryRun = false): array
    {
        $changes = [];
        $updatedIds = [];

        $comments ??= DirectoryContactComment::query()->orderBy('id')->get();

        foreach ($comments as $comment) {
            if (! $comment instanceof DirectoryContactComment) {
                continue;
            }

            // Read raw DB value — Attribute setter would already repair on get/set round-trip.
            $from = trim((string) ($comment->getAttributes()['comment'] ?? $comment->comment ?? ''));
            if ($from === '' || ! ExtractedTextNormalizer::looksCorrupted($from)) {
                continue;
            }

            $to = self::repairValue($from);
            if ($to === null || $to === '' || $to === $from) {
                continue;
            }

            $changes[] = [
                'id' => (int) $comment->getKey(),
                'field' => 'comment',
                'from' => $from,
                'to' => $to,
            ];

            if (! $dryRun) {
                // Bypass mutator ambiguity: write repaired text via query.
                DirectoryContactComment::query()
                    ->whereKey($comment->getKey())
                    ->update(['comment' => $to]);
                $updatedIds[(int) $comment->getKey()] = true;
            } else {
                $updatedIds[(int) $comment->getKey()] = true;
            }
        }

        $nameUpdated = 0;
        $contacts = DirectoryContact::query()->orderBy('id')->get();
        foreach ($contacts as $contact) {
            $from = trim((string) ($contact->company_name ?? ''));
            if ($from === '' || ! ExtractedTextNormalizer::looksCorrupted($from)) {
                continue;
            }

            $to = self::repairValue($from);
            if ($to === null || $to === '' || $to === $from) {
                continue;
            }

            $changes[] = [
                'id' => (int) $contact->getKey(),
                'field' => 'directory_company_name',
                'from' => $from,
                'to' => $to,
            ];

            if (! $dryRun) {
                $contact->forceFill(['company_name' => $to])->save();
                $nameUpdated++;
            } else {
                $nameUpdated++;
            }
        }

        return [
            'updated' => count($updatedIds) + $nameUpdated,
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
