<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Collection;

class LithuanianCompanyNameNormalizer
{
    /**
     * Canonical legal-form tokens (uppercase match key → display form).
     *
     * @var array<string, string>
     */
    private const FORMS = [
        'VŠĮ' => 'VšĮ',
        'VSI' => 'VšĮ',
        'KŪB' => 'KŪB',
        'KUB' => 'KŪB',
        'TŪB' => 'TŪB',
        'TUB' => 'TŪB',
        'UAB' => 'UAB',
        'AB' => 'AB',
        'MB' => 'MB',
        'IĮ' => 'IĮ',
        'II' => 'IĮ',
    ];

    public static function isLithuanianContact(Contact $contact): bool
    {
        $country = mb_strtolower(trim((string) ($contact->company_country ?? '')));

        if (in_array($country, ['lithuania', 'lietuva', 'lt', 'ltu'], true)) {
            return true;
        }

        // Empty country but clearly LT legal form in the name.
        $name = trim((string) ($contact->company_name ?? ''));
        if ($name === '') {
            return false;
        }

        return self::extract($name)['form'] !== null
            && ($country === '' || $country === '—');
    }

    /**
     * @return array{name: string, form: ?string}
     */
    public static function extract(string $raw): array
    {
        $name = self::stripQuotes(trim($raw));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B,;.");

        if ($name === '') {
            return ['name' => '', 'form' => null];
        }

        $formPattern = self::formRegex();

        // Leading form: "UAB Name" / "MB Name"
        if (preg_match('/^'.$formPattern.'\s+(.+)$/iu', $name, $m) === 1) {
            return [
                'name' => trim($m[2], " \t,"),
                'form' => self::canonicalForm($m[1]),
            ];
        }

        // Trailing form with or without comma: "Name, UAB" / "Name UAB"
        if (preg_match('/^(.+?)(?:\s*,\s*|\s+)('.$formPattern.')$/iu', $name, $m) === 1) {
            return [
                'name' => trim($m[1], " \t,"),
                'form' => self::canonicalForm($m[2]),
            ];
        }

        return ['name' => $name, 'form' => null];
    }

    public static function normalize(string $raw): string
    {
        $parts = self::extract($raw);
        $name = self::formatNamePart($parts['name']);
        $form = $parts['form'];

        if ($name === '') {
            return $form ?? '';
        }

        if ($form === null) {
            return $name;
        }

        return $name.', '.$form;
    }

    /**
     * @return array{updated: int, changes: list<array{id: int, from: string, to: string}>}
     */
    public static function normalizeContacts(?Collection $contacts = null, bool $dryRun = false): array
    {
        $contacts ??= Contact::query()->orderBy('id')->get();
        $changes = [];
        $updated = 0;

        foreach ($contacts as $contact) {
            if (! $contact instanceof Contact || ! self::isLithuanianContact($contact)) {
                continue;
            }

            $from = trim((string) $contact->company_name);
            $to = self::normalize($from);

            if ($to === '' || $to === $from) {
                continue;
            }

            $changes[] = ['id' => (int) $contact->getKey(), 'from' => $from, 'to' => $to];

            if (! $dryRun) {
                $contact->forceFill(['company_name' => $to])->save();
                $updated++;
            }
        }

        return [
            'updated' => $dryRun ? count($changes) : $updated,
            'changes' => $changes,
        ];
    }

    private static function stripQuotes(string $value): string
    {
        return str_replace(
            ['"', '"', '"', '„', '“', '”', '«', '»', "'", '′'],
            '',
            $value,
        );
    }

    private static function formRegex(): string
    {
        $alts = array_map(
            static fn (string $form): string => preg_quote($form, '/'),
            array_keys(self::FORMS),
        );

        // Longer tokens first (VŠĮ before AB).
        usort($alts, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return '('.implode('|', $alts).')';
    }

    private static function canonicalForm(string $form): string
    {
        $key = mb_strtoupper(trim($form), 'UTF-8');

        return self::FORMS[$key] ?? mb_strtoupper($form, 'UTF-8');
    }

    private static function formatNamePart(string $name): string
    {
        $name = trim($name, " \t,");
        if ($name === '') {
            return '';
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $name) ?? '';
        if ($letters === '' || mb_strtoupper($letters, 'UTF-8') !== $letters) {
            return $name;
        }

        // ALL CAPS → title case, keep short acronyms (DSV, IT) uppercase.
        $words = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        $out = '';
        foreach ($words as $word) {
            if (preg_match('/^\s+$/u', $word) === 1) {
                $out .= $word;
                continue;
            }

            $wordLetters = preg_replace('/[^\p{L}]/u', '', $word) ?? '';
            if ($wordLetters !== '' && mb_strlen($wordLetters) <= 3) {
                $out .= mb_strtoupper($word, 'UTF-8');
                continue;
            }

            $out .= mb_convert_case(mb_strtolower($word, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return $out;
    }
}
