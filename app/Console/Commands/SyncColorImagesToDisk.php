<?php

namespace App\Console\Commands;

use App\Models\Color;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SyncColorImagesToDisk extends Command
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    protected $signature = 'app:sync-color-images
                            {--source-disk=public : Disk where images already exist (e.g. public for local storage/app/public)}
                            {--target-disk=s3 : Disk to upload to (typically s3)}
                            {--prefix=colors : File path prefix inside the disk (e.g. colors)}
                            {--scan-filesystem : Discover local files by collection slug and color code under the prefix}
                            {--update-db : When used with --scan-filesystem, save discovered paths on colors.image}
                            {--only-missing : Skip files that already exist on the target disk}
                            {--dry-run : Do not upload; only print what would be copied}';

    protected $description = 'Upload local color swatch images to production storage (S3). Run from your PC, not on Cloud.';

    public function handle(): int
    {
        $sourceDiskName = (string) $this->option('source-disk');
        $targetDiskName = (string) $this->option('target-disk');
        $prefix = trim((string) $this->option('prefix'), '/');
        $scanFilesystem = (bool) $this->option('scan-filesystem');
        $updateDb = (bool) $this->option('update-db');
        $onlyMissing = (bool) $this->option('only-missing');
        $dryRun = (bool) $this->option('dry-run');

        $sourceDisk = Storage::disk($sourceDiskName);
        $targetDisk = Storage::disk($targetDiskName);

        if ($prefix !== '') {
            $this->info("Syncing images under prefix: {$prefix}/");
        }

        $colors = Color::query()
            ->with('collection:id,name')
            ->orderBy('collection_id')
            ->orderBy('color_code')
            ->get(['id', 'collection_id', 'color_code', 'color_name', 'image']);

        $total = $colors->count();
        $this->info("Processing {$total} color(s) from source disk: {$sourceDiskName}");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $uploaded = 0;
        $skipped = 0;
        $missingOnSource = 0;
        $failed = 0;
        $dbUpdated = 0;

        foreach ($colors as $color) {
            $relativePath = $scanFilesystem
                ? $this->resolveFilesystemPath($sourceDisk, $prefix, $color)
                : ltrim((string) $color->image, '/');

            if ($relativePath === null || $relativePath === '') {
                $missingOnSource++;
                $slug = Str::lower((string) $color->collection?->name);
                $code = (string) (int) $color->color_code;
                $this->warn("Source missing for color_id={$color->id}: {$slug}/{$code}");
                continue;
            }

            if ($scanFilesystem && $updateDb && $color->image !== $relativePath) {
                if (! $dryRun) {
                    $color->update(['image' => $relativePath]);
                }
                $dbUpdated++;
            }

            if (! $sourceDisk->exists($relativePath)) {
                $missingOnSource++;
                $this->warn("Source missing for color_id={$color->id}: {$relativePath}");
                continue;
            }

            if ($onlyMissing && $targetDisk->exists($relativePath)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $uploaded++;
                $this->line("[dry-run] Would upload: {$relativePath}");
                continue;
            }

            try {
                $contents = $sourceDisk->get($relativePath);

                $options = [];

                // Ensure S3 objects are publicly readable (so Storage::url() works).
                if ($targetDiskName === 's3') {
                    $options['visibility'] = 'public';
                }

                $targetDisk->put($relativePath, $contents, $options);
                $uploaded++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed uploading {$relativePath}: ".$e->getMessage());
            }
        }

        $this->info('--- Sync summary ---');
        $this->info("Source disk: {$sourceDiskName}");
        $this->info("Target disk: {$targetDiskName}");
        $this->info("Total colors: {$total}");
        $this->info("Uploaded: {$uploaded}");
        $this->info("Skipped (only-missing): {$skipped}");
        $this->info("Missing on source: {$missingOnSource}");
        if ($scanFilesystem && $updateDb) {
            $this->info("DB paths updated: {$dbUpdated}");
        }
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveFilesystemPath($sourceDisk, string $prefix, Color $color): ?string
    {
        $slug = Str::lower((string) $color->collection?->name);
        $code = (string) (int) $color->color_code;

        if ($slug === '' || $code === '') {
            return null;
        }

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $path = $prefix !== ''
                ? "{$prefix}/{$slug}/{$code}.{$extension}"
                : "{$slug}/{$code}.{$extension}";

            if ($sourceDisk->exists($path)) {
                return $path;
            }
        }

        if (filled($color->image) && $sourceDisk->exists((string) $color->image)) {
            return (string) $color->image;
        }

        return null;
    }
}

