<?php

namespace App\Support;

use Illuminate\Support\Str;
use Normalizer;

/**
 * Repair PDF/OCR text that arrives as UTF-16 leftovers, Baltic codepages,
 * custom font byte shifts, or invalid UTF-8 (Livewire rejects the latter).
 */
class ExtractedTextNormalizer
{
    /**
     * @var list<string>
     */
    private const SCORE_NEEDLES = [
        'pvm', 'suma', 'kodas', 'uab', 'invoice', 'saskait', 'sąskait', 'eur', 'bank',
        'moket', 'futura', 'pardav', 'pirk', 'serija', 'mokestin', 'rinkliav', 'atliek',
        'sratc', 'formavimo', 'pareikalauta', 'pranesim', 'pranešim', 'seb', 'swedbank',
        'išrašas', 'israsas', 'operacij', 'iban',
    ];

    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $seeds = [self::sanitizeUtf8($text)];

        // Real UTF-16 PDF text must be decoded before NULs are stripped.
        $nullCount = substr_count($text, "\0");
        if ($nullCount >= 8) {
            $even = strlen($text) % 2 === 0 ? $text : $text."\0";
            foreach (['UTF-16LE', 'UTF-16BE'] as $enc) {
                $utf16 = @mb_convert_encoding($even, 'UTF-8', $enc);
                if (is_string($utf16) && $utf16 !== '') {
                    $seeds[] = self::sanitizeUtf8($utf16);
                }
            }
        }

        $best = '';
        $bestScore = PHP_INT_MIN;

        foreach (array_unique($seeds) as $seed) {
            if ($seed === '') {
                continue;
            }

            foreach (self::candidates($seed) as $candidate) {
                $candidate = self::sanitizeUtf8($candidate);
                if ($candidate === '') {
                    continue;
                }

                $score = self::readableScore($candidate);
                if ($best === '' || self::isBetterCandidate($candidate, $score, $best, $bestScore)) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }
        }

        return self::finalizeLithuanian($best);
    }

    private static function isBetterCandidate(string $candidate, int $score, string $best, int $bestScore): bool
    {
        if ($score > $bestScore) {
            return true;
        }

        if ($score < $bestScore) {
            return false;
        }

        // Tie-break: never keep box-drawing mojibake when a clean candidate scores the same.
        $candidateBad = self::looksCorrupted($candidate);
        $bestBad = self::looksCorrupted($best);
        if ($candidateBad !== $bestBad) {
            return ! $candidateBad;
        }

        $candidateLt = self::hasLithuanianLetters($candidate);
        $bestLt = self::hasLithuanianLetters($best);
        if ($candidateLt !== $bestLt) {
            return $candidateLt;
        }

        return false;
    }

    /**
     * Safe for Livewire JSON / MySQL utf8mb4 — strip NULs and invalid sequences.
     */
    public static function sanitizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // UTF-16BE/LE BOM leftovers often become "\0X\0Y…" — drop NULs early.
        $text = str_replace("\0", '', $text);

        // Drop invalid UTF-8 bytes without introducing "?".
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        }

        $encoded = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        if (is_string($encoded)) {
            $decoded = json_decode($encoded);
            if (is_string($decoded)) {
                $text = $decoded;
            }
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = is_string($stripped) ? $stripped : $text;

        return str_replace(["\u{FFFD}", '�'], '', $text);
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $text): array
    {
        $out = [$text];

        $stripped = str_replace("\0", '', $text);

        // Custom PDF font encodings (e.g. SRATC) shift letters by -0x1D but keep digits.
        // Skip when text is CP437/OEM mojibake — shifting ASCII turns "Achilles" into "^chilles".
        if (! self::looksCorrupted($stripped) && self::readableScore($stripped) < 4) {
            $out[] = self::shiftLatinBytes($stripped, 0x1D);
        }

        $source = $stripped !== '' ? $stripped : $text;
        $corrupted = self::looksCorrupted($source);

        // Codepage re-decodes invent junk when text is already Unicode (good LT or CP437 glyphs).
        $skipCodepages = $corrupted || self::hasLithuanianLetters($source);

        if (! $skipCodepages) {
            foreach (['Windows-1257', 'ISO-8859-13', 'Windows-1252', 'ISO-8859-1'] as $encoding) {
                $converted = @iconv($encoding, 'UTF-8//IGNORE', $source);
                if (is_string($converted) && $converted !== '' && $converted !== $text) {
                    $out[] = $converted;
                }
            }

            $mojibake = self::fixUtf8Mojibake($source);
            if ($mojibake !== null) {
                $out[] = $mojibake;
            }
        }

        $cp437 = self::repairCp437Mojibake($source);
        if ($cp437 !== null) {
            $out[] = $cp437;
        }

        return array_values(array_unique($out));
    }

    /**
     * True when text has box-drawing / dagger leftovers typical of UTF-8 read as CP437/OEM.
     */
    public static function looksCorrupted(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        // Box drawing (│─┼ etc.), daggers, and similar OCR/console leftovers.
        if (preg_match('/[\x{2500}-\x{257F}†‡]/u', $text) === 1) {
            return true;
        }

        // “V†í–«” / “┼áiauli” style: high-bit Latin junk next to ASCII company words.
        return preg_match('/[ÅÃÄÂðñ][\x{0080}-\x{00FF}\x{2013}-\x{2022}]/u', $text) === 1
            && ! self::hasLithuanianLetters($text);
    }

    public static function hasLithuanianLetters(string $text): bool
    {
        return preg_match('/[ąčęėįšųūžĄČĘĖĮŠŲŪŽ]/u', $text) === 1;
    }

    /**
     * UTF-8 bytes were misread as CP437 (OEM) → Unicode box-drawing; reverse that.
     * Uses a per-character glyph→byte map so ASCII round-trips (iconv//IGNORE does not).
     */
    public static function repairCp437Mojibake(string $text): ?string
    {
        if (! self::looksCorrupted($text) || ! function_exists('iconv')) {
            return null;
        }

        static $glyphToByte = null;
        if ($glyphToByte === null) {
            $glyphToByte = [];
            for ($b = 0; $b <= 255; $b++) {
                $utf = @iconv('CP437', 'UTF-8', chr($b));
                if (is_string($utf) && $utf !== '') {
                    $glyphToByte[$utf] = chr($b);
                }
            }
        }

        $bytes = '';
        $extended = 0;
        $length = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if (! isset($glyphToByte[$ch])) {
                return null;
            }
            $byte = $glyphToByte[$ch];
            $bytes .= $byte;
            if (ord($byte) >= 0x80) {
                $extended++;
            }
        }

        if ($extended === 0 || ! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        $repaired = self::sanitizeUtf8($bytes);
        if ($repaired === '' || $repaired === $text) {
            return null;
        }

        // Accept only clear improvements (real letters / no box junk).
        if (self::looksCorrupted($repaired)) {
            return null;
        }

        // CP437→UTF-8 of real language text always yields diacritics; reject ASCII junk.
        if (! preg_match('/[ąčęėįšųūžĄČĘĖĮŠŲŪŽäöüáéíóúñÄÖÜÁÉÍÓÚÑćčđšžĆČĐŠŽ]/u', $repaired)) {
            return null;
        }

        return $repaired;
    }

    private static function shiftLatinBytes(string $text, int $delta): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($text[$i]);
            if (
                $ord === 10 || $ord === 13
                || ($ord >= 0x30 && $ord <= 0x39)
                || in_array($ord, [0x20, 0x2D, 0x2E, 0x2F, 0x3A, 0x40, 0x5F], true)
            ) {
                $out .= $text[$i];
            } elseif ($ord >= 0x01 && $ord <= 0x61) {
                $shifted = $ord + $delta;
                $out .= $shifted <= 0xFF ? chr($shifted) : $text[$i];
            } else {
                $out .= $text[$i];
            }
        }

        foreach (['ISO-8859-13', 'Windows-1257', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $out);
            if (is_string($converted) && $converted !== '' && self::readableScore($converted) >= self::readableScore($out)) {
                return $converted;
            }
        }

        return $out;
    }

    private static function fixUtf8Mojibake(string $text): ?string
    {
        // Classic “Å¡” / “Ä—” style double-encoding for Lithuanian.
        if (! preg_match('/Ã.|Ä.|Å.|â.|ð./u', $text)) {
            return null;
        }

        // Interpret current UTF-8 bytes as Latin-1 code units, then re-decode as UTF-8.
        $asLatin1 = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        if (! is_string($asLatin1) || $asLatin1 === '') {
            return null;
        }

        $asUtf8 = @mb_convert_encoding($asLatin1, 'UTF-8', 'ISO-8859-1');
        if (! is_string($asUtf8) || $asUtf8 === '' || $asUtf8 === $text) {
            return null;
        }

        return $asUtf8;
    }

    private static function finalizeLithuanian(string $text): string
    {
        // NFC so ė/š/ž compare and store consistently.
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }

        // Common PDF box-drawing / private-use leftovers next to Lithuanian letters.
        $text = str_replace(["\u{FFFD}", '�'], '', $text);

        return trim(preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text);
    }

    public static function readableScore(string $text): int
    {
        $hay = Str::lower($text);
        $score = 0;

        foreach (self::SCORE_NEEDLES as $needle) {
            if (str_contains($hay, $needle)) {
                $score++;
            }
        }

        if (preg_match('/\blt\d{2}/u', $hay) === 1) {
            $score++;
        }

        // Prefer text with real Lithuanian letters over ASCII-only garbage.
        if (self::hasLithuanianLetters($text)) {
            $score += 2;
        }

        // Penalize leftover replacement / C1 control density.
        $bad = preg_match_all('/[�\x80-\x9F]/u', $text) ?: 0;
        $score -= min(5, (int) floor($bad / 20));

        // Penalize CP437/OEM box-drawing mojibake (┼│─†).
        $boxes = preg_match_all('/[\x{2500}-\x{257F}†‡]/u', $text) ?: 0;
        $score -= min(8, $boxes);

        return $score;
    }
}
