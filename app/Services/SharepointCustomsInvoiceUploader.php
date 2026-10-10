<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\ImportTaxPayment;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SharepointCustomsInvoiceUploader
{
    public const FOLDER_NAME = 'customs-invoices';

    /**
     * {root}/customs-invoices/{YYYY}/{YYYY-MM}
     */
    public static function folderPathForCargo(Cargo $cargo, ?SharepointSetting $settings = null): string
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

        $date = $cargo->actual_date ?? $cargo->estimated_arrival ?? now();
        $year = $date->format('Y');
        $monthKey = $date->format('Y-m');

        $segments[] = self::FOLDER_NAME;
        $segments[] = $client->sanitizeFolderName($year);
        $segments[] = $client->sanitizeFolderName($monthKey);

        return implode('/', $segments);
    }

    /**
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string}
     */
    public static function ingestFromLocalPath(Cargo $cargo, string $localPath, ?string $originalName = null): array
    {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Customs invoices are stored only on SharePoint.');
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($localPath)) {
            throw new RuntimeException('Uploaded file is missing.');
        }

        $binary = $disk->get($localPath);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Could not read the uploaded customs invoice.');
        }

        $fallbackName = basename(str_replace('\\', '/', $localPath));
        $fileName = trim((string) ($originalName ?: $fallbackName));

        if ($fileName === '') {
            $fileName = 'customs-invoice.pdf';
        }

        $result = self::ingestBinary($cargo, $binary, $fileName);
        self::deleteLocalPath($localPath);

        return $result;
    }

    /**
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string}
     */
    public static function ingestBinary(Cargo $cargo, string $binary, string $fileName): array
    {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Customs invoices are stored only on SharePoint.');
        }

        if ($binary === '') {
            throw new RuntimeException('Could not read the uploaded customs invoice.');
        }

        $folderPath = self::folderPathForCargo($cargo, $settings);
        $client = SharepointGraphClient::make($settings);
        $remoteName = self::remoteFileName($cargo, $fileName, $client);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);
        $path = $folderPath.'/'.$remoteName;

        $cargo->forceFill([
            'customs_invoice_file_name' => $remoteName,
            'customs_invoice_sharepoint_web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
            'customs_invoice_sharepoint_item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'customs_invoice_sharepoint_path' => $path,
        ])->save();

        Log::info('Customs invoice stored on SharePoint', [
            'cargo_id' => $cargo->getKey(),
            'path' => $path,
            'item_id' => $item['id'] ?? null,
        ]);

        $format = strtolower((string) pathinfo($remoteName, PATHINFO_EXTENSION)) ?: 'bin';
        ActivityLogger::logFileUploaded('Customs invoice', $format, $cargo, [
            'file_name' => $remoteName,
            'sharepoint_path' => $path,
            'sharepoint_item_id' => $item['id'] ?? null,
        ]);

        return [
            'name' => $remoteName,
            'path' => $path,
            'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
        ];
    }

    /**
     * Upload extra files next to the customs invoice on SharePoint.
     *
     * @param  list<string>  $localPaths
     * @param  array<string, string>  $originalNames
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string, original_name: string, uploaded_at: string}>
     */
    public static function ingestAdditionalDocuments(
        ImportTaxPayment $payment,
        array $localPaths,
        array $originalNames = [],
    ): array {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Import tax documents are stored only on SharePoint.');
        }

        $payment->loadMissing('cargo');
        $cargo = $payment->cargo;

        if ($cargo === null) {
            throw new RuntimeException('Warehouse order is missing for this import tax payment.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path) && is_string($path)));
        $meta = $payment->additionalSharepointDocuments();

        if ($localPaths === []) {
            return $meta;
        }

        $folderPath = self::folderPathForCargo($cargo, $settings);
        $client = SharepointGraphClient::make($settings);

        foreach ($localPaths as $localPath) {
            if (! Storage::disk('public')->exists($localPath)) {
                throw new RuntimeException('Uploaded file is missing: '.$localPath);
            }

            $binary = Storage::disk('public')->get($localPath);

            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not read the uploaded document.');
            }

            $fallbackName = basename(str_replace('\\', '/', $localPath));
            $originalName = trim((string) ($originalNames[$localPath] ?? $originalNames[$fallbackName] ?? ''));

            if ($originalName === '') {
                $originalName = $fallbackName;
            }

            $remoteName = self::additionalRemoteFileName($cargo, $originalName, $meta, $client);
            $item = $client->uploadBinary($folderPath, $remoteName, $binary);
            $path = $folderPath.'/'.$remoteName;

            $meta[] = [
                'name' => $remoteName,
                'original_name' => $originalName,
                'path' => $path,
                'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
                'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        $payment->forceFill([
            'documents' => $meta,
        ])->save();

        foreach ($localPaths as $localPath) {
            self::deleteLocalPath($localPath);
        }

        Log::info('Import tax additional documents stored on SharePoint', [
            'import_tax_payment_id' => $payment->getKey(),
            'cargo_id' => $cargo->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        ActivityLogger::logFileUploaded('Import tax documents', 'multi', $payment, [
            'count' => count($meta),
            'cargo_id' => $cargo->getKey(),
            'sharepoint_folder' => $folderPath,
            'file_names' => array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
                $meta,
            ))),
        ]);

        return $meta;
    }

    public static function remoteFileName(
        Cargo $cargo,
        string $originalName,
        ?SharepointGraphClient $client = null,
    ): string {
        $client ??= SharepointGraphClient::make();
        $safe = $client->sanitizeFileName($originalName !== '' ? $originalName : 'customs-invoice.pdf');
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'customs-invoice';
        $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));

        if ($ext === '') {
            $ext = 'pdf';
        }

        $date = ($cargo->actual_date ?? $cargo->estimated_arrival ?? now())->format('Y-m-d');
        $id = filled($cargo->getKey()) ? (string) $cargo->getKey() : 'new';

        return $id.'-'.$date.'-customs-'.$base.'.'.$ext;
    }

    /**
     * @param  list<array{name?: string}>  $existing
     */
    public static function additionalRemoteFileName(
        Cargo $cargo,
        string $originalName,
        array $existing = [],
        ?SharepointGraphClient $client = null,
    ): string {
        $client ??= SharepointGraphClient::make();
        $safe = $client->sanitizeFileName($originalName !== '' ? $originalName : 'document.pdf');
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'document';
        $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));

        if ($ext === '') {
            $ext = 'pdf';
        }

        $date = ($cargo->actual_date ?? $cargo->estimated_arrival ?? now())->format('Y-m-d');
        $id = filled($cargo->getKey()) ? (string) $cargo->getKey() : 'new';
        $remoteName = $id.'-'.$date.'-additional-'.$base.'.'.$ext;

        $used = [];
        foreach ($existing as $row) {
            if (is_array($row) && filled($row['name'] ?? null)) {
                $used[strtolower((string) $row['name'])] = true;
            }
        }

        if (! isset($used[strtolower($remoteName)])) {
            return $remoteName;
        }

        $i = 2;
        while (isset($used[strtolower($id.'-'.$date.'-additional-'.$base.'-'.$i.'.'.$ext)])) {
            $i++;
        }

        return $id.'-'.$date.'-additional-'.$base.'-'.$i.'.'.$ext;
    }

    private static function deleteLocalPath(string $localPath): void
    {
        try {
            Storage::disk('public')->delete($localPath);
        } catch (\Throwable) {
            // Local cleanup is best-effort.
        }
    }
}
