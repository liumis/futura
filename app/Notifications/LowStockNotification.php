<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    public function __construct(
        public int $productCount,
        public float $alertLimit,
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
        $limit = rtrim(rtrim(number_format($this->alertLimit, 3, '.', ''), '0'), '.');

        return [
            'type' => NotificationType::LowStock->value,
            'title' => 'Low stock',
            'body' => $this->productCount === 1
                ? '1 product is below the '.$limit.' m alert limit.'
                : $this->productCount.' products are below the '.$limit.' m alert limit.',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
            'url' => route('filament.admin.pages.reports-low-stock'),
            'link_label' => 'View low stock report',
            'count' => $this->productCount,
        ];
    }
}
