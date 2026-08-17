<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification
{
    public function __construct(
        public int $orderId,
        public string $customerLabel,
        public string $totalFormatted,
        public string $statusLabel,
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
            'type' => NotificationType::CustomerOrders->value,
            'title' => 'New order #'.$this->orderId,
            'body' => 'Customer: '.$this->customerLabel
                .' · Total: '.$this->totalFormatted
                .' · Status: '.$this->statusLabel,
            'icon' => 'heroicon-o-shopping-bag',
            'color' => 'primary',
            'url' => $this->url,
            'link_label' => 'View order',
            'customer' => $this->customerLabel,
            'total' => $this->totalFormatted,
            'status' => $this->statusLabel,
            'order_id' => $this->orderId,
        ];
    }
}
