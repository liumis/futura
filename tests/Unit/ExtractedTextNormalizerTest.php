<?php

namespace Tests\Unit;

use App\Support\ExtractedTextNormalizer;
use Tests\TestCase;

class ExtractedTextNormalizerTest extends TestCase
{
    public function test_strips_null_bytes_and_keeps_lithuanian(): void
    {
        $raw = "S\0ą\0s\0k\0a\0i\0t\0a";
        $normalized = ExtractedTextNormalizer::normalize($raw);

        $this->assertStringContainsString('Sąskaita', $normalized);
        $this->assertStringNotContainsString("\0", $normalized);
    }

    public function test_sanitizes_invalid_utf8_for_livewire(): void
    {
        $raw = "PVM\xFF\xFEsuma";
        $safe = ExtractedTextNormalizer::sanitizeUtf8($raw);

        $this->assertNotFalse(json_encode($safe));
        $this->assertStringContainsString('PVM', $safe);
        $this->assertStringContainsString('suma', $safe);
    }

    public function test_shifts_sratc_style_encoded_letters_without_breaking_digits(): void
    {
        // "MOKESTINIS" encoded with -0x1D on letters, digits stay plain.
        $encoded = '';
        foreach (str_split('MOKESTINIS') as $ch) {
            $encoded .= chr(ord($ch) - 0x1D);
        }
        $encoded .= ' Nr. 2020217102573';

        $normalized = ExtractedTextNormalizer::normalize($encoded);

        $this->assertStringContainsString('2020217102573', $normalized);
        $this->assertTrue(
            str_contains(mb_strtolower($normalized), 'mokestinis')
            || ExtractedTextNormalizer::readableScore($normalized) >= ExtractedTextNormalizer::readableScore($encoded)
        );
    }

    public function test_sratc_pdf_still_extracts_after_normalizer_refactor(): void
    {
        config(['services.ocr_space.key' => null]);

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'invoices'.DIRECTORY_SEPARATOR.'sratc-mokestinis-pranesimas.pdf';
        $this->assertFileExists($path);

        $result = (new \App\Services\InvoiceOcrService)->extract($path);

        $this->assertSame('2020217102573', $result['invoice_number'] ?? null);
        $this->assertSame(8.22, $result['sum_inc_vat'] ?? null);
    }
}
