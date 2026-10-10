<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SharepointSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SharepointInvoiceUploader
{
    public const FOLDER_NAME = 'invoices';

    /**
     * {root}/invoices/{YYYY}/{YYYY-MM}
     */
    public static function folderPathForInvoice(Invoice $invoice, ?SharepointSetting $settings = null): string
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

        $date = $invoice->invoice_date ?? now();
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
    public static function ingestBinary(Invoice $invoice, string $binary, string $fileName, ?string $localPath = null): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Invoice PDFs are stored only on SharePoint.');
        }

        if ($binary === '') {
            throw new RuntimeException('Could not read the uploaded invoice file.');
        }

        $folderPath = self::folderPathForInvoice($invoice, $settings);
        $client = SharepointGraphClient::make($settings);
        $remoteName = self::remoteFileName($invoice, $fileName, $client);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);
        $path = $folderPath.'/'.$remoteName;

        $invoice->forceFill([
            'sharepoint_web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
            'sharepoint_item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'sharepoint_path' => $path,
            'file_name' => $remoteName,
            'file_mime' => $invoice->file_mime ?: 'application/octet-stream',
            'pdf_path' => '',
            'file_content' => null,
        ])->save();

        if (filled($localPath)) {
            self::deleteLocalPath((string) $localPath);
        }

        Log::info('Invoice file stored on SharePoint', [
            'invoice_id' => $invoice->getKey(),
            'path' => $path,
            'item_id' => $item['id'] ?? null,
        ]);

        $format = strtolower((string) pathinfo($remoteName, PATHINFO_EXTENSION)) ?: 'bin';
        ActivityLogger::logFileUploaded('Invoice '.$invoice->displayNumber(), $format, $invoice, [
            'file_name' => $remoteName,
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
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
     * Upload extra files (CMR, delivery notes, …) next to the invoice on SharePoint.
     *
     * @param  list<string>  $localPaths
     * @param  array<string, string>  $originalNames  Stored path => original filename
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string, original_name: string, uploaded_at: string}>
     */
    public static function ingestAdditionalDocuments(Invoice $invoice, array $localPaths, array $originalNames = []): array
    {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Additional invoice documents are stored only on SharePoint.');
        }

        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path) && is_string($path)));
        if ($localPaths === []) {
            return $invoice->additionalDocuments();
        }

        if (! $invoice->exists) {
            throw new RuntimeException('Save the invoice before uploading additional documents.');
        }

        $folderPath = self::folderPathForInvoice($invoice, $settings);
        $client = SharepointGraphClient::make($settings);
        $meta = $invoice->additionalDocuments();

        foreach ($localPaths as $localPath) {
            if (! Storage::disk('public')->exists($localPath)) {
                throw new RuntimeException('Uploaded file is missing: '.$localPath);
            }

            $binary = Storage::disk('public')->get($localPath);
            if (! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not read the uploaded additional document.');
            }

            $fallbackName = basename(str_replace('\\', '/', $localPath));
            $originalName = trim((string) ($originalNames[$localPath] ?? $originalNames[$fallbackName] ?? ''));
            if ($originalName === '') {
                $originalName = $fallbackName;
            }
            $remoteName = self::additionalRemoteFileName($invoice, $originalName, $meta, $client);
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

        $invoice->forceFill([
            'additional_documents' => $meta,
        ])->save();

        foreach ($localPaths as $localPath) {
            self::deleteLocalPath($localPath);
        }

        Log::info('Invoice additional documents stored on SharePoint', [
            'invoice_id' => $invoice->getKey(),
            'count' => count($meta),
            'folder' => $folderPath,
        ]);

        ActivityLogger::logFileUploaded('Invoice '.$invoice->displayNumber().' additional documents', 'multi', $invoice, [
            'count' => count($meta),
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'sharepoint_folder' => $folderPath,
            'file_names' => array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['name']) ? (string) $row['name'] : null,
                $meta,
            ))),
        ]);

        return $meta;
    }

    /**
     * @param  list<array{name?: string}>  $existing
     */
    public static function additionalRemoteFileName(
        Invoice $invoice,
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

        $date = $invoice->invoice_date?->format('Y-m-d') ?: now()->format('Y-m-d');
        $id = filled($invoice->getKey()) ? (string) $invoice->getKey() : 'new';
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

        $fileBase = pathinfo($remoteName, PATHINFO_FILENAME);
        $n = 2;

        do {
            $candidate = $fileBase.'-'.$n.'.'.$ext;
            $n++;
        } while (isset($used[strtolower($candidate)]));

        return $candidate;
    }

    /**
     * Upload a language-specific sales PDF and merge into sharepoint_files.
     * Primary sharepoint_* fields follow $setAsPrimary.
     *
     * @return array{name: string, path: string, item_id: ?string, web_url: ?string, lang: string}
     */
    public static function ingestLanguageBinary(
        Invoice $invoice,
        string $binary,
        string $lang,
        bool $setAsPrimary = false,
    ): array {
        $settings = SharepointSetting::instance();
        if (! $settings->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Invoice PDFs are stored only on SharePoint.');
        }

        if ($binary === '') {
            throw new RuntimeException('Could not read the invoice PDF.');
        }

        $lang = strtolower(trim($lang));
        $base = ($invoice->invoice_number ?? 'invoice-'.$invoice->id).'-'.strtoupper($lang).'.pdf';
        $folderPath = self::folderPathForInvoice($invoice, $settings);
        $client = SharepointGraphClient::make($settings);
        $remoteName = self::remoteFileName($invoice, $base, $client);
        $item = $client->uploadBinary($folderPath, $remoteName, $binary);
        $path = $folderPath.'/'.$remoteName;

        $entry = [
            'lang' => $lang,
            'name' => $remoteName,
            'path' => $path,
            'item_id' => filled($item['id'] ?? null) ? (string) $item['id'] : null,
            'web_url' => filled($item['webUrl'] ?? null) ? (string) $item['webUrl'] : null,
        ];

        $files = [];
        foreach ($invoice->sharepointFiles() as $existing) {
            if (strtolower((string) ($existing['lang'] ?? '')) === $lang) {
                continue;
            }
            $files[] = $existing;
        }
        $files[] = $entry;

        $payload = [
            'sharepoint_files' => $files,
            'file_mime' => 'application/pdf',
            'pdf_path' => '',
            'file_content' => null,
        ];

        if ($setAsPrimary || ! $invoice->hasSharepointLink() || blank($invoice->sharepoint_item_id)) {
            $payload['sharepoint_web_url'] = $entry['web_url'];
            $payload['sharepoint_item_id'] = $entry['item_id'];
            $payload['sharepoint_path'] = $entry['path'];
            $payload['file_name'] = $entry['name'];
        }

        $invoice->forceFill($payload)->save();

        Log::info('Invoice language PDF stored on SharePoint', [
            'invoice_id' => $invoice->getKey(),
            'lang' => $lang,
            'path' => $path,
            'item_id' => $entry['item_id'],
        ]);

        ActivityLogger::logFileUploaded(
            'Invoice '.$invoice->displayNumber().' ('.strtoupper($lang).')',
            'pdf',
            $invoice,
            [
                'file_name' => $remoteName,
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'language' => $lang,
                'sharepoint_path' => $path,
                'sharepoint_item_id' => $entry['item_id'],
            ],
        );

        return $entry;
    }

    public static function remoteFileName(Invoice $invoice, string $originalName, ?SharepointGraphClient $client = null): string
    {
        $client ??= SharepointGraphClient::make();
        $safe = $client->sanitizeFileName($originalName !== '' ? $originalName : 'invoice.pdf');
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'invoice';
        $ext = strtolower((string) pathinfo($safe, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'pdf';
        }

        $date = $invoice->invoice_date?->format('Y-m-d') ?: now()->format('Y-m-d');
        $id = filled($invoice->getKey()) ? (string) $invoice->getKey() : 'new';

        return $id.'-'.$date.'-'.$base.'.'.$ext;
    }

    public static function deleteLocalPath(string $path): void
    {
        foreach (['public', 'local'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (Throwable $exception) {
                Log::warning('Failed deleting local invoice file', [
                    'disk' => $disk,
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
