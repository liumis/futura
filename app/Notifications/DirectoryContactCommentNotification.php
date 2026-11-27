<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class DirectoryContactCommentNotification extends Notification
{
    public function __construct(
        public int $directoryContactId,
        public string $contactName,
        public string $commentPreview,
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
        $preview = trim($this->commentPreview);
        $body = $this->contactName;
        if ($preview !== '') {
            $body .= ' — '.$preview;
        }

        return [
            'type' => NotificationType::Personal->value,
            'title' => 'New comment on contact',
            'body' => $body,
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => 'info',
            'url' => $this->url,
            'link_label' => 'Open contact',
            'directory_contact_id' => $this->directoryContactId,
        ];
    }
}
