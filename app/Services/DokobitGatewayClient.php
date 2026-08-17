<?php

namespace App\Services;

use App\Models\DokobitSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DokobitGatewayClient
{
    public function __construct(
        protected DokobitSetting $settings,
    ) {}

    public static function make(): self
    {
        return new self(DokobitSetting::instance());
    }

    public function accessToken(): string
    {
        $token = $this->settings->activeAccessToken();

        if (! filled($token)) {
            throw new RuntimeException('Dokobit access token is not configured. Set it under System → Dokobit.');
        }

        return (string) $token;
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->settings->activeApiUrl(), '/');
    }

    /**
     * Upload a PDF using base64 content (works without a public file URL).
     *
     * @return array{token: string, status: string}
     */
    public function uploadFileContent(string $filename, string $binary): array
    {
        $digest = hash('sha256', $binary);

        $response = $this->post('file/upload', [
            'file' => [
                'name' => $filename,
                'digest' => $digest,
                'content' => base64_encode($binary),
            ],
        ]);

        if (($response['status'] ?? null) !== 'ok' || blank($response['token'] ?? null)) {
            throw new RuntimeException($this->errorMessage($response, 'Dokobit file upload failed.'));
        }

        return [
            'token' => (string) $response['token'],
            'status' => (string) ($response['status'] ?? 'ok'),
        ];
    }

    /**
     * Poll until file status is "uploaded".
     */
    public function waitUntilUploaded(string $fileToken, int $maxAttempts = 30, int $sleepSeconds = 2): void
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = $this->get('file/upload/'.$fileToken.'/status');

            $status = (string) ($response['status'] ?? '');

            if ($status === 'uploaded') {
                return;
            }

            if ($status !== '' && $status !== 'pending' && $status !== 'ok') {
                throw new RuntimeException($this->errorMessage($response, 'Dokobit file upload status failed.'));
            }

            sleep($sleepSeconds);
        }

        throw new RuntimeException('Timed out waiting for Dokobit file upload.');
    }

    /**
     * @param  list<array<string, mixed>>  $signers
     * @param  list<array{token: string}>  $files
     * @return array{token: string, signers: array<string, string>}
     */
    public function createSigning(
        string $name,
        array $signers,
        array $files,
        ?string $postbackUrl = null,
        string $type = 'pdf',
        string $language = 'en',
    ): array {
        $payload = [
            'type' => $type,
            'name' => $name,
            'language' => $language,
            'signers' => $signers,
            'files' => $files,
        ];

        if (filled($postbackUrl)) {
            $payload['postback_url'] = $postbackUrl;
        }

        $response = $this->post('signing/create', $payload);

        if (($response['status'] ?? null) !== 'ok' || blank($response['token'] ?? null)) {
            throw new RuntimeException($this->errorMessage($response, 'Dokobit signing create failed.'));
        }

        /** @var array<string, string> $signerTokens */
        $signerTokens = [];
        foreach (($response['signers'] ?? []) as $key => $token) {
            $signerTokens[(string) $key] = (string) $token;
        }

        return [
            'token' => (string) $response['token'],
            'signers' => $signerTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function signingStatus(string $signingToken): array
    {
        return $this->get('signing/'.$signingToken.'/status');
    }

    public function signingUrl(string $signingToken, string $signerAccessToken): string
    {
        return $this->apiBaseUrl().'/signing/'.$signingToken.'?access_token='.$signerAccessToken;
    }

    public function downloadSignedFile(string $fileUrl): string
    {
        $url = $fileUrl;
        if (! str_contains($url, 'access_token=')) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.'access_token='.urlencode($this->accessToken());
        }

        $response = Http::timeout(180)
            ->withOptions(['verify' => false])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Could not download signed file from Dokobit (HTTP '.$response->status().').');
        }

        $body = $response->body();

        if ($body === '') {
            throw new RuntimeException('Dokobit returned an empty signed file.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function post(string $action, array $fields): array
    {
        $url = $this->apiBaseUrl().'/api/'.$action.'.json?access_token='.urlencode($this->accessToken());

        $response = Http::asForm()
            ->timeout(180)
            ->withOptions(['verify' => false])
            ->post($url, $this->flatten($fields));

        return $this->decode($response->body(), $response->status());
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $action): array
    {
        $url = $this->apiBaseUrl().'/api/'.$action.'.json?access_token='.urlencode($this->accessToken());

        $response = Http::timeout(180)
            ->withOptions(['verify' => false])
            ->get($url);

        return $this->decode($response->body(), $response->status());
    }

    /**
     * Flatten nested arrays into PHP form style keys for Dokobit (signers[0][name], etc.).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                if ($this->isList($value)) {
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $result += $this->flatten($item, $fullKey.'['.$index.']');
                        } else {
                            $result[$fullKey.'['.$index.']'] = $item;
                        }
                    }
                } else {
                    $result += $this->flatten($value, $fullKey);
                }
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $value
     */
    protected function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $body, int $httpStatus): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid Dokobit response (HTTP '.$httpStatus.').');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorMessage(array $response, string $fallback): string
    {
        $message = (string) ($response['message'] ?? $fallback);
        $errors = $response['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $message .= ' '.implode(' ', array_map('strval', $errors));
        }

        return $message;
    }
}
