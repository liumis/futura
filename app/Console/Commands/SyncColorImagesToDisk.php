<?php

namespace App\Console\Commands;

use App\Models\Color;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncColorImagesToDisk extends Command
{
    protected $signature = 'app:sync-color-images
                            {--source-disk=public : Disk where images already exist (e.g. public for local storage/app/public)}
                            {--target-disk=s3 : Disk to upload to (typically s3)}
                            {--prefix=colors : File path prefix inside the disk (e.g. colors)}
                            {--only-missing : Skip files that already exist on the target disk}
                            {--dry-run : Do not upload; only print what would be copied}';

    protected $description = 'Upload local color swatch images to production storage (S3) so they display on prod.';

    public function handle(): int
    {
        $sourceDiskName = (string) $this->option('source-disk');
        $targetDiskName = (string) $this->option('target-disk');
        $prefix = trim((string) $this->option('prefix'), '/');
        $onlyMissing = (bool) $this->option('only-missing');
        $dryRun = (bool) $this->option('dry-run');

        $sourceDisk = Storage::disk($sourceDiskName);
        $targetDisk = Storage::disk($targetDiskName);

        if ($prefix !== '') {
            $this->info("Syncing images under prefix: {$prefix}/");
        }

        $colors = Color::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->when($prefix !== '', function ($q) use ($prefix): void {
                $q->where('image', 'like', $prefix.'/%');
            })
            ->select(['id', 'image'])
            ->get();

        $total = $colors->count();
        $this->info("Found {$total} color(s) with images on source disk: {$sourceDiskName}");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $uploaded = 0;
        $skipped = 0;
        $missingOnSource = 0;
        $failed = 0;

        foreach ($colors as $color) {
            $relativePath = ltrim((string) $color->image, '/');

            if ($relativePath === '') {
                continue;
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
        $this->info("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

