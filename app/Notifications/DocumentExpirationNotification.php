<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class DocumentExpirationNotification extends Notification
{
    /**
     * @param  'two_weeks'|'tomorrow'  $stage
     */
    public function __construct(
        public int $documentId,
        public string $documentName,
        public string $url,
        public string $stageLabel,
        public string $validToDateLabel,
        public string $stage,
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
            'title' => 'Document expiration reminder',
            'body' => $this->stageLabel.' — '.$this->documentName.' (valid to '.$this->validToDateLabel.')',
            'icon' => 'heroicon-o-clock',
            'color' => $this->stage === 'tomorrow' ? 'warning' : 'gray',
            'url' => $this->url,
            'link_label' => 'Open document',
            'document_id' => $this->documentId,
            'stage' => $this->stage,
        ];
    }
}
