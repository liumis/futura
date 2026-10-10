<?php

namespace App\Console\Commands;

use App\Services\ColorHexFromImage;
use Illuminate\Console\Command;

class FillColorHexFromImages extends Command
{
    protected $signature = 'colors:fill-hex
                            {--force : Overwrite hex values that are already set}';

    protected $description = 'Autofill colors.hex from swatch images (center-average RGB).';

    public function handle(ColorHexFromImage $extractor): int
    {
        $force = (bool) $this->option('force');

        $this->info($force
            ? 'Filling hex from images (overwriting existing values)...'
            : 'Filling hex from images (only empty values)...');

        $result = $extractor->fillAll(
            force: $force,
            onProgress: function ($color, string $status, ?string $hex = null): void {
                $label = trim(($color->collection?->name ?? '').' / '.($color->color_name ?? $color->color_code));

                match ($status) {
                    'updated' => $this->line("{$label} → {$hex}"),
                    'missing_image' => $this->warn("No image: {$label}"),
                    'failed' => $this->error("Failed: {$label}"),
                    default => null,
                };
            },
        );

        $this->newLine();
        $this->info('--- Hex fill summary ---');
        $this->info("Updated: {$result['updated']}");
        $this->info("Skipped (already set): {$result['skipped']}");
        $this->info("Missing image: {$result['missing_image']}");
        $this->info("Failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
