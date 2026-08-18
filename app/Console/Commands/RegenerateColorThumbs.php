<?php

namespace App\Console\Commands;

use App\Models\Color;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RegenerateColorThumbs extends Command
{
    protected $signature = 'colors:regenerate-thumbs
                            {--max-size=400 : Maximum width or height in pixels}
                            {--quality=82 : JPEG quality (1-100)}
                            {--dry-run : Do not write files; only report what would change}';

    protected $description = 'Rebuild color swatch JPEGs from files already in storage (no website scrape).';

    public function handle(): int
    {
        $maxSize = max(64, (int) $this->option('max-size'));
        $quality = min(100, max(40, (int) $this->option('quality')));
        $dryRun = (bool) $this->option('dry-run');
        $diskName = Color::storageDisk();
        $disk = Storage::disk($diskName);

        $this->info("Rebuilding thumbs on disk [{$diskName}] at max {$maxSize}px.");

        $rewritten = 0;
        $skipped = 0;
        $failed = 0;

        Color::query()
            ->with('collection:id,name')
            ->orderBy('collection_id')
            ->orderBy('color_code')
            ->cursor()
            ->each(function (Color $color) use ($disk, $diskName, $maxSize, $quality, $dryRun, &$rewritten, &$skipped, &$failed): void {
                $sourcePath = ltrim((string) $color->image, '/');
                $label = Str::lower((string) $color->collection?->name).'/'.((int) $color->color_code);

                if ($sourcePath === '' || ! $disk->exists($sourcePath)) {
                    $skipped++;
                    $this->warn("Missing: {$label}");

                    return;
                }

                $targetPath = $this->targetPath($color);

                if ($dryRun) {
                    $rewritten++;
                    $this->line("[dry-run] Would rewrite {$sourcePath} -> {$targetPath}");

                    return;
                }

                $tmpSource = tempnam(sys_get_temp_dir(), 'ft-thumb-in-');
                $tmpJpeg = null;

                if ($tmpSource === false) {
                    $failed++;
                    $this->error("Temp file failed: {$label}");

                    return;
                }

                try {
                    $stream = $disk->readStream($sourcePath);

                    if ($stream === false || $stream === null) {
                        throw new RuntimeException('Could not read source file.');
                    }

                    $out = fopen($tmpSource, 'wb');

                    if ($out === false) {
                        fclose($stream);
                        throw new RuntimeException('Could not open temp file.');
                    }

                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    fclose($stream);

                    $tmpJpeg = $this->writeJpegThumb($tmpSource, $maxSize, $quality);
                    $jpegStream = fopen($tmpJpeg, 'rb');

                    if ($jpegStream === false) {
                        throw new RuntimeException('Could not read generated JPEG.');
                    }

                    try {
                        $options = $diskName === 's3' ? ['visibility' => 'public'] : [];
                        $disk->writeStream($targetPath, $jpegStream, $options);
                    } finally {
                        if (is_resource($jpegStream)) {
                            fclose($jpegStream);
                        }
                    }

                    if ($color->image !== $targetPath) {
                        if ($sourcePath !== $targetPath) {
                            $disk->delete($sourcePath);
                        }
                        $color->update(['image' => $targetPath]);
                    }

                    $rewritten++;
                    $this->line("Rewrote {$label}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Failed {$label}: ".$e->getMessage());
                } finally {
                    if (is_file($tmpSource)) {
                        unlink($tmpSource);
                    }
                    if (is_string($tmpJpeg) && is_file($tmpJpeg)) {
                        unlink($tmpJpeg);
                    }
                }
            });

        $this->info('--- Thumb summary ---');
        $this->info("Rewritten: {$rewritten}");
        $this->info("Missing: {$skipped}");
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function targetPath(Color $color): string
    {
        $slug = Str::lower((string) $color->collection?->name);
        $code = (string) (int) $color->color_code;

        return "colors/{$slug}/{$code}.jpg";
    }

    private function writeJpegThumb(string $sourcePath, int $maxSize, int $quality): string
    {
        $source = $this->loadImage($sourcePath);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSize / max($width, $height, 1));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        if ($thumb === false) {
            imagedestroy($source);
            throw new RuntimeException('Could not create thumb canvas.');
        }

        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $target = $sourcePath.'.jpg';

        if (! imagejpeg($thumb, $target, $quality)) {
            imagedestroy($thumb);
            throw new RuntimeException('Could not encode JPEG.');
        }

        imagedestroy($thumb);

        return $target;
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
}
