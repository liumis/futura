<?php

namespace App\Services;

use App\Models\Color;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ColorHexFromImage
{
    /**
     * Extract a representative #RRGGBB hex from a color's swatch image.
     * Samples the center region and averages non-near-white pixels (thumb padding).
     */
    public function extractFromColor(Color $color): ?string
    {
        $path = ltrim((string) $color->image, '/');

        if ($path === '') {
            return null;
        }

        $disk = Storage::disk(Color::storageDisk());

        if (! $disk->exists($path)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'color-hex-');

        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        try {
            $stream = $disk->readStream($path);

            if ($stream === false || $stream === null) {
                throw new RuntimeException('Could not read color image.');
            }

            $out = fopen($tmp, 'wb');

            if ($out === false) {
                fclose($stream);
                throw new RuntimeException('Could not open temporary file.');
            }

            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            return $this->extractFromPath($tmp);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function extractFromPath(string $path): ?string
    {
        $image = $this->loadImage($path);

        try {
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width < 1 || $height < 1) {
                return null;
            }

            $x0 = (int) floor($width * 0.2);
            $y0 = (int) floor($height * 0.2);
            $x1 = (int) ceil($width * 0.8);
            $y1 = (int) ceil($height * 0.8);

            $sumR = 0;
            $sumG = 0;
            $sumB = 0;
            $count = 0;
            $fallbackR = 0;
            $fallbackG = 0;
            $fallbackB = 0;
            $fallbackCount = 0;

            $stepX = max(1, (int) floor(($x1 - $x0) / 48));
            $stepY = max(1, (int) floor(($y1 - $y0) / 48));

            for ($y = $y0; $y < $y1; $y += $stepY) {
                for ($x = $x0; $x < $x1; $x += $stepX) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $fallbackR += $r;
                    $fallbackG += $g;
                    $fallbackB += $b;
                    $fallbackCount++;

                    // Skip near-white pixels from thumb canvas padding / overexposed weave.
                    if ($r >= 245 && $g >= 245 && $b >= 245) {
                        continue;
                    }

                    $sumR += $r;
                    $sumG += $g;
                    $sumB += $b;
                    $count++;
                }
            }

            if ($count === 0) {
                if ($fallbackCount === 0) {
                    return null;
                }

                $sumR = $fallbackR;
                $sumG = $fallbackG;
                $sumB = $fallbackB;
                $count = $fallbackCount;
            }

            return sprintf(
                '#%02X%02X%02X',
                (int) round($sumR / $count),
                (int) round($sumG / $count),
                (int) round($sumB / $count),
            );
        } finally {
            imagedestroy($image);
        }
    }

    public function normalize(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $hex = trim($hex);

        if ($hex === '') {
            return null;
        }

        if (! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            return null;
        }

        return strtoupper($hex);
    }

    /**
     * @return \GdImage
     */
    private function loadImage(string $path)
    {
        $image = @imagecreatefromjpeg($path)
            ?: @imagecreatefrompng($path)
            ?: @imagecreatefromwebp($path)
            ?: @imagecreatefromgif($path);

        if ($image !== false) {
            return $image;
        }

        throw new RuntimeException('File is not a valid image.');
    }

    /**
     * Fill missing (or all) color hex values from swatch images.
     *
     * @return array{updated: int, skipped: int, failed: int, missing_image: int}
     */
    public function fillAll(bool $force = false, ?callable $onProgress = null): array
    {
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $missingImage = 0;

        Color::query()
            ->with('collection:id,name')
            ->orderBy('id')
            ->cursor()
            ->each(function (Color $color) use ($force, $onProgress, &$updated, &$skipped, &$failed, &$missingImage): void {
                if (! $force && filled($color->hex)) {
                    $skipped++;
                    $onProgress?->__invoke($color, 'skipped');

                    return;
                }

                if (blank($color->image)) {
                    $missingImage++;
                    $onProgress?->__invoke($color, 'missing_image');

                    return;
                }

                try {
                    $hex = $this->extractFromColor($color);

                    if ($hex === null) {
                        $failed++;
                        $onProgress?->__invoke($color, 'failed');

                        return;
                    }

                    $color->update(['hex' => $hex]);
                    $updated++;
                    $onProgress?->__invoke($color, 'updated', $hex);
                } catch (Throwable) {
                    $failed++;
                    $onProgress?->__invoke($color, 'failed');
                }
            });

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'missing_image' => $missingImage,
        ];
    }
}
