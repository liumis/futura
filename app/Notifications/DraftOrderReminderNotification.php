<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DraftOrderReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $orderId,
        public string $orderUrl,
        public string $totalFormatted,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) ($notifiable->name ?? ''));
        $greeting = $name !== '' ? 'Hello '.$name.',' : 'Hello,';

        return (new MailMessage)
            ->subject('Reminder: complete your draft order #'.$this->orderId)
            ->greeting($greeting)
            ->line('You have a draft order that has not been submitted yet.')
            ->line('Order #'.$this->orderId.' · Total: '.$this->totalFormatted)
            ->action('Continue order', $this->orderUrl)
            ->line('Please submit the order when you are ready, or discard it if it is no longer needed.');
    }
}
