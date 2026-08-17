<?php

namespace App\Support;

use Filament\Forms\Components\FileUpload;

class UploadLimits
{
    /** Maximum upload size in kilobytes (40 MB). */
    public const MAX_KILOBYTES = 40960;

    public const MAX_MEGABYTES = 40;

    public static function note(): string
    {
        return 'Maximum upload size: '.self::MAX_MEGABYTES.' MB.';
    }

    public static function withExistingNote(?string $existing = null): string
    {
        $note = self::note();

        if (! filled($existing)) {
            return $note;
        }

        $existing = trim((string) $existing);

        if (preg_match('/maximum (upload )?size:\s*\d+\s*MB/i', $existing) === 1) {
            return (string) preg_replace(
                '/Maximum (upload )?size:\s*\d+\s*MB\.?/i',
                rtrim($note, '.'),
                $existing,
            );
        }

        return rtrim($existing, '.').'. '.$note;
    }

    /**
     * Apply 40 MB filesize validation and helper note.
     */
    public static function apply(FileUpload $upload, ?string $helperText = null): FileUpload
    {
        return $upload
            ->maxSize(self::MAX_KILOBYTES)
            ->helperText(self::withExistingNote($helperText));
    }

    public static function configureDefaults(): void
    {
        FileUpload::configureUsing(function (FileUpload $upload): void {
            $upload->maxSize(self::MAX_KILOBYTES);
        });
    }
}
