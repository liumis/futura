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
        $text = self::sanitizeUtf8($text);
        if ($text === '') {
            return '';
        }

        $best = $text;
        $bestScore = self::readableScore($text);

        foreach (self::candidates($text) as $candidate) {
            $candidate = self::sanitizeUtf8($candidate);
            if ($candidate === '') {
                continue;
            }

            $score = self::readableScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return self::finalizeLithuanian($best);
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

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($converted) && $converted !== '') {
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

        return is_string($stripped) ? $stripped : $text;
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $text): array
    {
        $out = [$text];

        $nullCount = substr_count($text, "\0");
        $stripped = str_replace("\0", '', $text);

        if ($nullCount >= 8) {
            $out[] = $stripped;

            $even = strlen($text) % 2 === 0 ? $text : $text."\0";
            foreach (['UTF-16LE', 'UTF-16BE'] as $enc) {
                $utf16 = @mb_convert_encoding($even, 'UTF-8', $enc);
                if (is_string($utf16) && $utf16 !== '') {
                    $out[] = $utf16;
                    $out[] = str_replace("\0", '', $utf16);
                }
            }
        }

        // Custom PDF font encodings (e.g. SRATC) shift letters by -0x1D but keep digits.
        if ($nullCount >= 8 || self::readableScore($stripped) < 4) {
            $out[] = self::shiftLatinBytes($stripped, 0x1D);
        }

        foreach (['Windows-1257', 'ISO-8859-13', 'Windows-1252', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $stripped !== '' ? $stripped : $text);
            if (is_string($converted) && $converted !== '' && $converted !== $text) {
                $out[] = $converted;
            }
        }

        $mojibake = self::fixUtf8Mojibake($stripped !== '' ? $stripped : $text);
        if ($mojibake !== null) {
            $out[] = $mojibake;
        }

        return array_values(array_unique($out));
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
        if (preg_match('/[ąčęėįšųūž]/iu', $text) === 1) {
            $score += 2;
        }

        // Penalize leftover replacement / C1 control density.
        $bad = preg_match_all('/[�\x80-\x9F]/u', $text) ?: 0;
        $score -= min(5, (int) floor($bad / 20));

        return $score;
    }
}
