<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    public function __construct(
        public int $colorCount,
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
            'type' => NotificationType::LowStock->value,
            'title' => 'Low stock',
            'body' => $this->colorCount === 1
                ? '1 color is below its recommended stock.'
                : $this->colorCount.' colors are below their recommended stock.',
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
            'url' => route('filament.admin.pages.reports-low-stock'),
            'link_label' => 'View low stock report',
            'count' => $this->colorCount,
        ];
    }
}
