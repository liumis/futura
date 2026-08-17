<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class DocumentApprovalRequestNotification extends Notification
{
    public function __construct(
        public int $documentId,
        public string $documentName,
        public string $url,
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
            'title' => 'Document awaiting your approval',
            'body' => $this->documentName,
            'icon' => 'heroicon-o-document-check',
            'color' => 'warning',
            'url' => $this->url,
            'link_label' => 'Open document',
            'document_id' => $this->documentId,
        ];
    }
}
