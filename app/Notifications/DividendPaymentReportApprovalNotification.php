<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class DividendPaymentReportApprovalNotification extends Notification
{
    public function __construct(
        public int $reportId,
        public string $reportName,
        public string $url,
    ) {
    }

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
            'title' => 'Dividend report awaiting confirmation',
            'body' => $this->reportName,
            'icon' => 'heroicon-o-document-check',
            'color' => 'warning',
            'url' => $this->url,
            'link_label' => 'Open dividends',
            'report_id' => $this->reportId,
        ];
    }
}

