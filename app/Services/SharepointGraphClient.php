<?php

namespace App\Services;

use App\Models\SharepointSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SharepointGraphClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const UPLOAD_LIMIT_BYTES = 4 * 1024 * 1024;

    public function __construct(
        protected SharepointSetting $settings,
    ) {}

    public static function make(?SharepointSetting $settings = null): self
    {
        return new self($settings ?? SharepointSetting::instance());
    }

    public function uploadBinary(string $folderPath, string $fileName, string $binary): array
    {
        $driveId = $this->resolveDriveId();
        $folderPath = $this->normalizePath($folderPath);
        $fileName = $this->sanitizeFileName($fileName);

        $this->ensureFolderPath($driveId, $folderPath);

        $itemPath = $folderPath === ''
            ? $fileName
            : $folderPath.'/'.$fileName;

        if (strlen($binary) <= self::UPLOAD_LIMIT_BYTES) {
            return $this->simpleUpload($driveId, $itemPath, $binary);
        }

        return $this->sessionUpload($driveId, $itemPath, $binary);
    }

    public function downloadItemContent(string $itemId): string
    {
        $driveId = $this->resolveDriveId();
        $url = self::GRAPH_BASE.'/drives/'.$driveId.'/items/'.$itemId.'/content';

        $response = Http::withToken($this->accessToken())
            ->timeout(120)
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable) {
                $json = null;
            }

            throw new RuntimeException($this->errorMessage(
                is_array($json) ? $json : null,
                $response->status(),
                'SharePoint file download failed.',
            ));
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new RuntimeException('SharePoint returned an empty file.');
        }

        return $binary;
    }

    /**
     * @return array<string, mixed>
     */
    public function getItem(string $itemId): array
    {
        $driveId = $this->resolveDriveId();
        $url = self::GRAPH_BASE.'/drives/'.$driveId.'/items/'.$itemId
            .'?$select=id,name,webUrl,createdDateTime,lastModifiedDateTime,fileSystemInfo';

        $response = $this->http()->get($url);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage(
                $response->json(),
                $response->status(),
                'SharePoint file metadata fetch failed.',
            ));
        }

        return $response->json() ?? [];
    }

    public function deleteItem(string $itemId): void
    {
        $driveId = $this->resolveDriveId();
        $url = self::GRAPH_BASE.'/drives/'.$driveId.'/items/'.$itemId;

        $response = $this->http()->delete($url);

        // 204 No Content = deleted; 404 = already gone.
        if (! $response->successful() && $response->status() !== 404) {
            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable) {
                $json = null;
            }

            throw new RuntimeException($this->errorMessage(
                is_array($json) ? $json : null,
                $response->status(),
                'SharePoint file delete failed.',
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function simpleUpload(string $driveId, string $itemPath, string $binary): array
    {
        $encodedPath = $this->encodePath($itemPath);
        $url = self::GRAPH_BASE.'/drives/'.$driveId.'/root:/'.$encodedPath.':/content';

        $response = $this->http()
            ->withBody($binary, 'application/octet-stream')
            ->put($url);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status(), 'SharePoint file upload failed.'));
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionUpload(string $driveId, string $itemPath, string $binary): array
    {
        $encodedPath = $this->encodePath($itemPath);
        $sessionResponse = $this->http()->post(
            self::GRAPH_BASE.'/drives/'.$driveId.'/root:/'.$encodedPath.':/createUploadSession',
            [
                'item' => [
                    '@microsoft.graph.conflictBehavior' => 'replace',
                    'name' => basename(str_replace('\\', '/', $itemPath)),
                ],
            ],
        );

        if (! $sessionResponse->successful()) {
            throw new RuntimeException($this->errorMessage($sessionResponse->json(), $sessionResponse->status(), 'SharePoint upload session failed.'));
        }

        $uploadUrl = (string) ($sessionResponse->json('uploadUrl') ?? '');
        if ($uploadUrl === '') {
            throw new RuntimeException('SharePoint did not return an upload URL.');
        }

        $size = strlen($binary);
        $chunkSize = 320 * 1024 * 10; // 3.2 MB (multiple of 320 KiB)
        $offset = 0;
        $result = null;

        while ($offset < $size) {
            $chunk = substr($binary, $offset, $chunkSize);
            $chunkLength = strlen($chunk);
            $end = $offset + $chunkLength - 1;

            $response = Http::withHeaders([
                'Content-Length' => (string) $chunkLength,
                'Content-Range' => "bytes {$offset}-{$end}/{$size}",
            ])->withBody($chunk, 'application/octet-stream')
                ->put($uploadUrl);

            if (! $response->successful() && $response->status() !== 202) {
                throw new RuntimeException($this->errorMessage($response->json(), $response->status(), 'SharePoint chunked upload failed.'));
            }

            $result = $response->json() ?? [];
            $offset += $chunkLength;
        }

        return is_array($result) ? $result : [];
    }

    public function ensureFolderPath(string $driveId, string $folderPath): void
    {
        $folderPath = $this->normalizePath($folderPath);
        if ($folderPath === '') {
            return;
        }

        $currentPath = '';
        $parentId = 'root';

        foreach (explode('/', $folderPath) as $segment) {
            if ($segment === '') {
                continue;
            }

            $currentPath = $currentPath === '' ? $segment : $currentPath.'/'.$segment;
            $encoded = $this->encodePath($currentPath);
            $existing = $this->http()->get(self::GRAPH_BASE.'/drives/'.$driveId.'/root:/'.$encoded);

            if ($existing->successful() && filled($existing->json('id'))) {
                $parentId = (string) $existing->json('id');

                continue;
            }

            $parentId = $this->createChildFolder($driveId, $parentId, $segment);
        }
    }

    protected function createChildFolder(string $driveId, string $parentId, string $name): string
    {
        $childrenUrl = $parentId === 'root'
            ? self::GRAPH_BASE.'/drives/'.$driveId.'/root/children'
            : self::GRAPH_BASE.'/drives/'.$driveId.'/items/'.$parentId.'/children';

        $create = $this->http()->post($childrenUrl, [
            'name' => $name,
            'folder' => new \stdClass,
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);

        if ($create->successful() && filled($create->json('id'))) {
            return (string) $create->json('id');
        }

        // Already exists (race) or filter-less fallback: resolve by path from parent listing.
        if ($create->status() === 409 || $create->status() === 400) {
            $list = $this->http()->get($childrenUrl, [
                '$select' => 'id,name,folder',
                '$top' => 200,
            ]);

            foreach ($list->json('value') ?? [] as $item) {
                if (($item['name'] ?? null) === $name && isset($item['id'])) {
                    return (string) $item['id'];
                }
            }
        }

        throw new RuntimeException($this->errorMessage($create->json(), $create->status(), 'Could not create SharePoint folder "'.$name.'".'));
    }

    public function resolveDriveId(): string
    {
        if (filled($this->settings->drive_id)) {
            return (string) $this->settings->drive_id;
        }

        $siteId = $this->resolveSiteId();

        if (filled($this->settings->document_library)) {
            $library = (string) $this->settings->document_library;
            $drives = $this->http()->get(self::GRAPH_BASE.'/sites/'.$siteId.'/drives');

            if (! $drives->successful()) {
                throw new RuntimeException($this->errorMessage($drives->json(), $drives->status(), 'Could not list SharePoint drives.'));
            }

            foreach ($drives->json('value') ?? [] as $drive) {
                if (strcasecmp((string) ($drive['name'] ?? ''), $library) === 0 && filled($drive['id'] ?? null)) {
                    return (string) $drive['id'];
                }
            }

            throw new RuntimeException('SharePoint document library "'.$library.'" was not found on the site.');
        }

        $drive = $this->http()->get(self::GRAPH_BASE.'/sites/'.$siteId.'/drive');
        if (! $drive->successful() || blank($drive->json('id'))) {
            throw new RuntimeException($this->errorMessage($drive->json(), $drive->status(), 'Could not resolve default SharePoint drive.'));
        }

        return (string) $drive->json('id');
    }

    public function resolveSiteId(): string
    {
        if (filled($this->settings->site_id)) {
            return (string) $this->settings->site_id;
        }

        $siteUrl = rtrim((string) $this->settings->site_url, '/');
        if ($siteUrl === '') {
            throw new RuntimeException('SharePoint site URL or site ID is required.');
        }

        $parts = parse_url($siteUrl);
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        if ($host === '') {
            throw new RuntimeException('SharePoint site URL is invalid.');
        }

        $path = $path === '' || $path === '/' ? '' : $path;
        $url = $path === ''
            ? self::GRAPH_BASE.'/sites/'.$host
            : self::GRAPH_BASE.'/sites/'.$host.':'.$path;

        $response = $this->http()->get($url);
        if (! $response->successful() || blank($response->json('id'))) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status(), 'Could not resolve SharePoint site.'));
        }

        return (string) $response->json('id');
    }

    public function accessToken(): string
    {
        $tenant = trim((string) $this->settings->tenant_id);
        $clientId = trim((string) $this->settings->client_id);
        $clientSecret = (string) $this->settings->client_secret;

        if ($tenant === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('SharePoint credentials are incomplete. Set them under System → SharePoint.');
        }

        $cacheKey = 'sharepoint.graph.token.'.hash('sha256', $tenant.'|'.$clientId);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ],
        );

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status(), 'SharePoint authentication failed.'));
        }

        $token = (string) $response->json('access_token');
        $expiresIn = max(60, (int) ($response->json('expires_in') ?? 3600) - 120);
        Cache::put($cacheKey, $token, $expiresIn);

        return $token;
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(120);
    }

    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path, "/ \t\n\r\0\x0B");
        $segments = array_values(array_filter(
            explode('/', $path),
            fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        return implode('/', array_map(fn (string $segment): string => $this->sanitizeFolderName($segment), $segments));
    }

    public function sanitizeFolderName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name) ?? $name;
        $name = trim($name, " .");

        return $name !== '' ? $name : 'Untitled';
    }

    public function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name) ?? $name;
        $name = trim($name);

        return $name !== '' ? $name : 'document.bin';
    }

    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function errorMessage(?array $payload, int $status, string $fallback): string
    {
        $message = $payload['error']['message']
            ?? $payload['error_description']
            ?? $payload['message']
            ?? null;

        if (is_string($message) && $message !== '') {
            return $fallback.' '.$message.' (HTTP '.$status.')';
        }

        return $fallback.' (HTTP '.$status.')';
    }
}
