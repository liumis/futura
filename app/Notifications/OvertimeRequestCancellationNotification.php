<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class OvertimeRequestCancellationNotification extends Notification
{
    public function __construct(
        public int $overtimeRequestId,
        public string $summary,
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
            'title' => 'Overtime cancellation awaiting your approval',
            'body' => $this->summary,
            'icon' => 'heroicon-o-x-circle',
            'color' => 'danger',
            'url' => $this->url,
            'link_label' => 'Open overtime request',
            'overtime_request_id' => $this->overtimeRequestId,
        ];
    }
}
