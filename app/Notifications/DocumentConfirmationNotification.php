<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class DocumentConfirmationNotification extends Notification
{
    public function __construct(
        public int $documentId,
        public string $documentName,
        public string $url,
        public string $dueAtLabel,
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
        return [
            'type' => NotificationType::Personal->value,
            'title' => 'Document confirmation required',
            'body' => 'Please view and confirm you have read: '.$this->documentName.' (due '.$this->dueAtLabel.')',
            'icon' => 'heroicon-o-clipboard-document-check',
            'color' => 'warning',
            'url' => $this->url,
            'link_label' => 'Open confirmation',
            'document_id' => $this->documentId,
        ];
    }
}
