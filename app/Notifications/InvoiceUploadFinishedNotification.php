<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InvoiceUploadFinishedNotification extends Notification
{
    public function __construct(
        public string $status,
        public string $body,
        public string $filename,
        public ?int $invoiceId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $title = match ($this->status) {
            'created' => 'Invoice uploaded',
            'draft' => 'Invoice saved as draft',
            'duplicate' => 'Duplicate invoice skipped',
            default => 'Invoice upload failed',
        };

        $url = $this->invoiceId !== null
            ? route('filament.admin.pages.invoices', ['edit_invoice' => $this->invoiceId])
            : route('filament.admin.pages.invoices');

        return [
            'type' => 'invoice_upload',
            'title' => $title,
            'body' => $this->body,
            'icon' => match ($this->status) {
                'created' => 'heroicon-o-check-circle',
                'draft' => 'heroicon-o-pencil-square',
                'duplicate' => 'heroicon-o-exclamation-triangle',
                default => 'heroicon-o-x-circle',
            },
            'color' => match ($this->status) {
                'created' => 'success',
                'draft' => 'warning',
                'duplicate' => 'warning',
                default => 'danger',
            },
            'url' => $url,
            'link_label' => $this->invoiceId !== null ? 'Open invoice' : 'Open invoices',
            'filename' => $this->filename,
            'invoice_id' => $this->invoiceId,
        ];
    }
}
