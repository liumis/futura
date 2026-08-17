<?php

namespace App\Notifications;

use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Illuminate\Notifications\Messages\MailMessage;

class FilamentResetPassword extends FilamentResetPasswordNotification
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $expireMinutes = (int) config('auth.passwords.users.expire');
        $logoUrl = rtrim((string) config('app.url'), '/').'/images/logo.svg';
        $appName = config('app.name');

        return (new MailMessage)
            ->subject("Reset your {$appName} password")
            ->view('mail.password-reset', [
                'actionUrl' => $this->url,
                'userName' => $notifiable->name,
                'expireMinutes' => $expireMinutes,
                'logoUrl' => $logoUrl,
                'appName' => $appName,
            ])
            ->text('mail.password-reset-plain', [
                'actionUrl' => $this->url,
                'userName' => $notifiable->name,
                'expireMinutes' => $expireMinutes,
                'appName' => $appName,
            ]);
    }
}
