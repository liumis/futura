<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\InvoiceUploadFinishedNotification;
use App\Services\InvoiceFromUploadCreator;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs after the HTTP response so multi-file OCR does not 504 behind Cloudflare.
 */
class ImportInvoiceUploadJob
{
    use Dispatchable;

    public function __construct(
        public string $relativePath,
        public string $originalFilename,
        public ?int $userId,
        public string $disk = 'public',
    ) {}

    public function handle(InvoiceFromUploadCreator $creator): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $result = $creator->createFromStoredPath(
                $this->relativePath,
                $this->disk,
                $this->userId,
                $this->originalFilename,
            );
        } catch (Throwable $exception) {
            Log::error('ImportInvoiceUploadJob failed', [
                'file' => $this->originalFilename,
                'message' => $exception->getMessage(),
            ]);
            $this->notifyUser(
                status: 'failed',
                body: $this->originalFilename.': '.$exception->getMessage(),
                invoiceId: null,
            );

            return;
        }

        $this->notifyUser(
            status: $result['status'],
            body: $result['message'],
            invoiceId: $result['invoice']?->getKey(),
        );
    }

    private function notifyUser(string $status, string $body, int|string|null $invoiceId): void
    {
        if ($this->userId === null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $user->notify(new InvoiceUploadFinishedNotification(
            status: $status,
            body: $body,
            filename: $this->originalFilename,
            invoiceId: $invoiceId !== null ? (int) $invoiceId : null,
        ));
    }
}
