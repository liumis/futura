<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\SharepointSetting;
use App\Services\DocumentBinaryStore;
use App\Services\SharepointDocumentUploader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateDocumentsToSharepoint extends Command
{
    protected $signature = 'app:migrate-documents-to-sharepoint
                            {--dry-run : List documents that would be migrated without uploading}
                            {--force : Re-upload even if SharePoint metadata already exists}';

    protected $description = 'Upload existing local document files to SharePoint and remove local copies';

    public function handle(): int
    {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady() && ! $this->option('dry-run')) {
            $this->error('SharePoint is not ready. Enable and configure System → SharePoint first.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $query = Document::query()->with('documentType')->orderBy('id');

        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        $query->each(function (Document $document) use ($force, $dryRun, &$migrated, &$skipped, &$failed): void {
            $localPath = $document->displayFilePath() ?: $document->file_path;
            $hasLocal = filled($localPath) && Storage::disk('public')->exists((string) $localPath);
            $hasSharepoint = $document->hasSharepointLink();

            if ($hasSharepoint && ! $force) {
                if ($hasLocal) {
                    if ($dryRun) {
                        $this->line("[dry-run] #{$document->id} already on SharePoint — would delete local {$localPath}");
                    } else {
                        DocumentBinaryStore::deleteLocalPaths([
                            $document->file_path,
                            $document->approved_file_path,
                        ]);
                        $document->forceFill([
                            'file_path' => null,
                            'approved_file_path' => null,
                        ])->save();
                        $this->info("#{$document->id} cleaned local file (already on SharePoint)");
                        $migrated++;
                    }
                } else {
                    $skipped++;
                }

                return;
            }

            if (! $hasLocal) {
                $this->warn("#{$document->id} skipped — no local file".($hasSharepoint ? '' : ' and no SharePoint link'));
                $skipped++;

                return;
            }

            if ($dryRun) {
                $this->line("[dry-run] #{$document->id} {$document->name} ← {$localPath}");
                $migrated++;

                return;
            }

            try {
                if ($hasSharepoint && $force) {
                    $binary = Storage::disk('public')->get((string) $localPath);
                    if (! is_string($binary) || $binary === '') {
                        throw new \RuntimeException('Local file is empty.');
                    }

                    $fileName = SharepointDocumentUploader::remoteFileName($document, (string) $localPath);
                    $result = DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $fileName);
                } else {
                    $result = DocumentBinaryStore::ingestLocalUpload($document->fresh(['documentType']));
                }

                $this->info("#{$document->id} → {$result['path']}");
                $migrated++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("#{$document->id} failed: ".$exception->getMessage());
            }
        });

        $this->newLine();
        $this->info("Done. migrated/cleaned={$migrated}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
