<?php

namespace App\Services;

use App\Models\BankStatementImport;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SharepointBankStatementUploader
{
    public const FOLDER_NAME = 'bank-statements';

    /**
     * {root}/bank-statements/{YYYY}/{YYYY-MM}
     */
    public static function folderPathForImport(BankStatementImport $import, ?SharepointSetting $settings = null): string
    {
        $settings ??= SharepointSetting::instance();
        $client = SharepointGraphClient::make($settings);

        $segments = [];

        if (filled($settings->root_folder_path)) {
            $root = $client->normalizePath((string) $settings->root_folder_path);
            if ($root !== '') {
                foreach (explode('/', $root) as $segment) {
                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                }
            }
        }

        $monthKey = self::monthKeyForImport($import);
        [$year, $month] = array_pad(explode('-', $monthKey, 2), 2, now()->format('m'));

        $segments[] = self::FOLDER_NAME;
        $segments[] = $client->sanitizeFolderName($year);
        $segments[] = $client->sanitizeFolderName($year.'-'.$month);

        return implode('/', $segments);
    }

    public static function monthKeyForImport(BankStatementImport $import): string
    {
        if ($import->period_from !== null) {
            return $import->period_from->format('Y-m');
        }

        if ($import->period_to !== null) {
            return $import->period_to->format('Y-m');
        }

        return now()->format('Y-m');
    }

    /**
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string}
     */
    public static function ingestLocalXml(BankStatementImport $import, string $xml, ?string $localPath = null): array
    {
        return self::ingestLocalFile($import, $xml, $localPath);
    }

    /**
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string}
     */
    public static function ingestLocalFile(BankStatementImport $import, string $binary, ?string $localPath = null): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Bank statements are stored only on SharePoint.');
        }

        if ($binary === '') {
            throw new RuntimeException('Could not read the uploaded bank statement file.');
        }

        $folderPath = self::folderPathForImport($import, $settings);
        $client = SharepointGraphClient::make($settings);
        $remoteName = self::remoteFileName($import, $client);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);
        $path = $folderPath.'/'.$remoteName;

        $import->forceFill([
            'sharepoint_web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
            'sharepoint_item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'sharepoint_path' => $path,
            'stored_path' => null,
        ])->save();

        if (filled($localPath)) {
            self::deleteLocalPath((string) $localPath);
        }

        Log::info('Bank statement stored on SharePoint', [
            'import_id' => $import->getKey(),
            'path' => $path,
            'item_id' => $item['id'] ?? null,
        ]);

        $format = strtolower((string) pathinfo($remoteName, PATHINFO_EXTENSION)) ?: 'bin';
        ActivityLogger::logFileUploaded('Bank statement', $format, $import, [
            'file_name' => $remoteName,
            'sharepoint_path' => $path,
            'sharepoint_item_id' => $item['id'] ?? null,
            'original_filename' => $import->original_filename,
            'imported_count' => $import->imported_count,
            'transaction_count' => $import->transaction_count,
        ]);

        return [
            'name' => $remoteName,
            'path' => $path,
            'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
        ];
    }

    public static function remoteFileName(BankStatementImport $import, ?SharepointGraphClient $client = null): string
    {
        $client ??= SharepointGraphClient::make();
        $original = filled($import->original_filename)
            ? (string) $import->original_filename
            : 'bank-statement.xml';
        $safe = $client->sanitizeFileName($original);
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'bank-statement';
        $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'xml';
        }

        $date = $import->period_from?->format('Y-m-d')
            ?: $import->period_to?->format('Y-m-d')
            ?: now()->format('Y-m-d');

        $id = filled($import->getKey()) ? (string) $import->getKey() : 'new';

        return $id.'-'.$date.'-'.$base.'.'.$ext;
    }

    public static function deleteLocalPath(string $path): void
    {
        try {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        } catch (Throwable $exception) {
            Log::warning('Failed deleting local bank statement file', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
